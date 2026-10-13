<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * Gives one person their own Lynomia identity inside one workspace, and keeps its role honest.
 *
 * THIS CLASS IS THE ANSWER TO "WHO IS SIGNED IN". Before it, the answer was "the store", and
 * everything that followed from that -- an unattributable audit trail, a warehouse packer with
 * administrator rights, one intercepted link costing the whole workspace -- followed from the
 * single shared identity rather than from any particular bug.
 *
 * WHY THE ADDRESS IS SYNTHETIC AND NOT THE PERSON'S REAL EMAIL. This is the one decision here
 * that looks wrong and is not. POST /platform/api/v1/users resolves
 * `User.from_email(...) || User.new(...)` and then grants the calling app a permissible on
 * whatever it resolved. Handed a real address that already exists in Lynomia it does not fail
 * and does not create: it hands this integration control of that person's existing Lynomia
 * account, in every workspace they belong to. A platform token cannot look an address up
 * first -- it may only read accounts it already holds -- so "does this email exist over there?"
 * is a question this side is structurally unable to ask before asking it is too late.
 *
 * Using the merchant's real email would therefore be exactly the "link by email similarity"
 * the approved rules forbid, wearing a friendlier face. So each person gets a deterministic
 * address derived from the store, the person and the installation, in a reserved .invalid
 * domain that can receive no mail and belong to nobody. Their real NAME travels with it, so
 * Lynomia's own audit trail reads correctly; only the login handle is synthetic, and nobody
 * ever types it, because the only way in is SSO.
 *
 * AND THE SYNTHETIC ADDRESS IS STILL CHECKED. It is derived from public facts, so on an
 * installation with open signup somebody could register it first and wait. Upstream applies
 * submitted attributes only on the create path, so the marker comes back only when this
 * integration is the thing that made the user. Absent means somebody got there first, and the
 * answer is to stop loudly rather than hand them a seat.
 *
 * ROLE MAPPING, AND WHY IT IS THIS AND NOT THE LARATRUST ROLE. Store Syria roles are
 * tenant-defined free text -- `type` is literally the role's name (UserController::store) --
 * so there is no fixed vocabulary to map from. The one distinction that is structural rather
 * than named is the one creatorId() already draws: a tenant OWNER is their own creator,
 * everybody else was created by them. Owner means administrator, everyone else means agent,
 * and there is deliberately no third branch and no way for a tenant to configure one.
 */
class IdentityProvisioner
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /**
     * This person's identity in this link's workspace, created if they do not have one yet and
     * re-synchronised if their role has changed since.
     *
     * IDEMPOTENT AND RESUMABLE WITHOUT A LOCK, which is why there isn't one. Every step is
     * either keyed on a deterministic address upstream (createUser), an upsert upstream
     * (attachAccountUser), or arbitrated by a unique index here. A process killed between the
     * remote calls and the local insert leaves a Lynomia user this method will resolve again
     * by address on the next attempt, with the marker still on it -- so the next attempt
     * finishes the job instead of making a second one.
     */
    public function identityFor(LynomiaStoreLink $link, User $person): LynomiaStoreIdentity
    {
        $accountId = $link->lynomia_account_id;

        if ($accountId === null) {
            // A caller asking for an identity in a workspace that does not exist is a bug in
            // the caller, not a state to paper over.
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'This store has no Lynomia workspace yet.'
            );
        }

        $role = $this->roleFor($person);
        $existing = LynomiaStoreIdentity::forWorkspacePerson((int) $accountId, (int) $person->id)->first();

        if ($existing !== null) {
            return $this->syncRole($existing, $role);
        }

        return $this->create($link, $person, (int) $accountId, $role);
    }

    /**
     * What this person may do in Lynomia.
     *
     * The tenant owner is their own creator; everyone else in the tenant was created by them.
     * That is the only distinction Store Syria draws that is not tenant-configurable free
     * text, and it is the right one: it is exactly the difference between the merchant and
     * the merchant's staff.
     */
    public function roleFor(User $person): string
    {
        return (int) $person->creatorId() === (int) $person->id
            ? LynomiaStoreIdentity::ROLE_ADMINISTRATOR
            : LynomiaStoreIdentity::ROLE_AGENT;
    }

    /**
     * Forget every identity in a workspace this app no longer holds.
     *
     * CALLED WHEN THE GRANT GOES BACK, because the two are the same fact. Lynomia releases the
     * per-user permissibles along with the account's, so the remote users these rows point at
     * are no longer ones this app may mint a link for. Keeping the rows would mean a later
     * re-link to the same workspace resolves them, skips creation, and fails at the SSO call
     * with an authorization error the merchant cannot act on.
     */
    public function forgetWorkspace(int $lynomiaAccountId): int
    {
        return LynomiaStoreIdentity::forWorkspace($lynomiaAccountId)->delete();
    }

    /**
     * Bring a stale role down -- or up -- to what the person holds today.
     *
     * A DEMOTION IN STORE SYRIA MUST REACH LYNOMIA, and nothing else does it: the identity
     * already exists, so the create path never runs again, and a member demoted from owner to
     * staff would otherwise keep administrator rights in the workspace for as long as the row
     * lived. attachAccountUser is an upsert upstream (find_or_initialize_by then update!), so
     * re-sending the pair changes the role rather than duplicating the membership.
     *
     * Only sent when the role actually differs, so the ordinary sign-in costs no extra call.
     */
    private function syncRole(LynomiaStoreIdentity $identity, string $role): LynomiaStoreIdentity
    {
        if ($identity->role === $role) {
            return $identity;
        }

        $this->api->attachAccountUser(
            (int) $identity->lynomia_account_id,
            (int) $identity->lynomia_user_id,
            $role
        );

        $previous = $identity->role;
        $identity->forceFill(['role' => $role])->save();

        AuditLog::record('lynomia_identity_role_changed', $identity, ['role' => $previous], [
            'role' => $role,
            'store_id' => $identity->store_id,
            'user_id' => $identity->user_id,
            'lynomia_account_id' => $identity->lynomia_account_id,
            'lynomia_user_id' => $identity->lynomia_user_id,
        ]);

        return $identity;
    }

    private function create(LynomiaStoreLink $link, User $person, int $accountId, string $role): LynomiaStoreIdentity
    {
        $marker = $this->marker($link, $person);

        $remote = $this->api->createUser(
            $this->displayName($person),
            $this->identityEmail($link, $person),
            // Generated, used once by the endpoint that requires the field, and dropped. It is
            // never stored, logged or shown, and nobody -- including this integration -- can
            // sign in with it afterwards. A repeated attempt generates a different one and
            // that changes nothing: upstream ignores the field for a user that already exists.
            Str::password(32),
            $marker
        );

        if (! $this->carriesMarker($remote, $marker)) {
            // Somebody else holds this address. See the class docblock: the marker is applied
            // upstream only on the create path, so its absence means this integration is not
            // what made the user -- and attaching a stranger to a merchant's workspace is the
            // takeover this whole design exists to refuse.
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'A Lynomia identity for this user is already in use. Please contact support.'
            );
        }

        $this->api->attachAccountUser($accountId, (int) $remote['id'], $role);

        $identity = $this->remember($link, $person, $accountId, (int) $remote['id'], $role);

        AuditLog::record('lynomia_identity_created', $identity, [], [
            'store_id' => $identity->store_id,
            'user_id' => $identity->user_id,
            'lynomia_account_id' => $identity->lynomia_account_id,
            'lynomia_user_id' => $identity->lynomia_user_id,
            'role' => $role,
        ]);

        return $identity;
    }

    /**
     * Write the mapping, or adopt the one a concurrent request just wrote.
     *
     * TWO TABS IS THE ORDINARY CASE, not an attack. Both requests resolve the same remote user
     * -- the address is deterministic and upstream returns the existing one -- so the only
     * thing left to settle is which local row survives, and the unique index settles it. The
     * loser re-reads rather than failing, because from the merchant's side both clicks did the
     * same correct thing.
     */
    private function remember(
        LynomiaStoreLink $link,
        User $person,
        int $accountId,
        int $remoteUserId,
        string $role
    ): LynomiaStoreIdentity {
        $attributes = [
            'lynomia_account_id' => $accountId,
            'user_id' => (int) $person->id,
            'lynomia_user_id' => $remoteUserId,
            'role' => $role,
            'store_id' => (int) $link->store_id,
        ];

        try {
            return LynomiaStoreIdentity::create($attributes);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $winner = LynomiaStoreIdentity::forWorkspacePerson($accountId, (int) $person->id)->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    /**
     * What identifies "this person, in this store, on this installation" to Lynomia.
     *
     * All three parts are load-bearing. The store and the installation are there for the
     * reasons the workspace marker documents -- store ids are per-database autoincrements, so
     * a production dump restored into staging collides on every one of them. The person is
     * there because that is the entire point: this is the fact that makes the identity theirs
     * rather than the store's.
     *
     * @return array<string,int|string>
     */
    private function marker(LynomiaStoreLink $link, User $person): array
    {
        return [
            'store_syria_store_id' => (int) $link->store_id,
            'store_syria_user_id' => (int) $person->id,
            'store_syria_installation' => $this->installationRef(),
        ];
    }

    /** @param array{custom_attributes?:array} $remote */
    private function carriesMarker(array $remote, array $marker): bool
    {
        $attributes = $remote['custom_attributes'] ?? [];

        foreach ($marker as $key => $value) {
            // String comparison: a JSON number and a form value are the same fact in two types.
            if (! is_array($attributes) || (string) ($attributes[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * A deterministic address that is unique per person per store and can belong to nobody.
     *
     * Deterministic so a retry resolves the same Lynomia user instead of minting a second.
     * Per person so two people are never the same actor. Per store so one person working in
     * two of their own stores has two separate identities -- a session scoped to one workspace
     * cannot then be anything at all in the other, which is per-store isolation held by
     * construction rather than by a check.
     *
     * .invalid is reserved by RFC 2606 and resolves nowhere: no mail is ever delivered, which
     * is correct, because this identity exists to be signed in to and not to be written to.
     */
    private function identityEmail(LynomiaStoreLink $link, User $person): string
    {
        $installation = preg_replace('/[^a-z0-9]+/', '-', strtolower($this->installationRef())) ?: 'local';

        return sprintf(
            'store-%d.user-%d.%s@%s',
            (int) $link->store_id,
            (int) $person->id,
            trim((string) $installation, '-'),
            'stores.storesyria.invalid'
        );
    }

    /**
     * The name Lynomia shows next to this person's replies.
     *
     * Their real one, because an audit trail that reads "store-14.user-9" is an audit trail
     * nobody can use. The email is synthetic for safety; the name has no such constraint and
     * attribution is the point of the exercise.
     */
    private function displayName(User $person): string
    {
        return Str::limit((string) ($person->name ?: "User {$person->id}"), 50, '');
    }

    private function installationRef(): string
    {
        return (string) config('lynomia.installation_ref', 'localhost');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
