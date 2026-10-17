<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;
use Workdo\LynomiaChat\app\Support\ProvisioningStage;

/**
 * Provisions a NEW Lynomia workspace for a store.
 *
 * This is the "create a new linked account" half of the approved flow. The other half --
 * attaching a workspace that already exists -- is AccountLinker, and the two are deliberately
 * separate classes because only one of them is allowed to call createUser().
 *
 * WHO IS IN THE WORKSPACE IS NOT THIS CLASS'S BUSINESS ANY MORE. It used to create one shared
 * service user and make it an administrator, and everyone on the merchant's team signed in as
 * it. People now get their own identity, with their own name and their own role, created on
 * first use by IdentityProvisioner -- which is also where the long argument about synthetic
 * addresses now lives, because that is where the address is minted.
 *
 * What is left here is the workspace itself, and one call into IdentityProvisioner to give the
 * tenant OWNER the administrator seat. A workspace with no administrator would be one nobody
 * could add a channel to, so it is created as part of provisioning rather than lazily.
 */
class AccountProvisioner
{
    public function __construct(
        private readonly PlatformApiClient $api,
        private readonly IdentityProvisioner $identities,
        private readonly SeatAllowanceSync $seats,
        private readonly EventSubscriptionManager $events
    ) {
    }

    /**
     * Provision a workspace for this store, resuming whatever a previous attempt left behind.
     *
     * THE WHOLE POINT OF THIS METHOD IS THAT IT CAN BE CALLED AGAIN. Provisioning is three
     * remote calls, two of which mint objects on the Lynomia side, and any of them can fail
     * or -- worse -- succeed with the answer lost on the way back. The old implementation
     * treated the happy path as the only path: it wrote all three results together at the
     * end, so a failure anywhere left a row with no ids and no record of what had been
     * created, and it returned early whenever a row existed, so the merchant could never
     * retry. The comment claiming "the next attempt resumes against the same row" described
     * an intention, not the code.
     *
     * FOUR PROPERTIES, each paid for somewhere specific:
     *
     *   repeated requests      A finished link returns immediately. An unfinished one resumes
     *                          from the first id it is missing, so no step is re-run.
     *   no duplicate accounts  Account creation is NOT transport-retried, and every attempt
     *                          asks Lynomia first whether this store already has a workspace.
     *                          A create whose answer was lost is adopted, not repeated.
     *   no orphaned mappings   Every id is persisted in the same breath as the call that
     *                          produced it, never batched. A process killed between calls
     *                          leaves a row that knows exactly what exists.
     *   one at a time          A conditional UPDATE decides which of two concurrent callers
     *                          proceeds; the other is told to wait rather than racing it.
     */
    public function provision(Store $store, User $actor): LynomiaStoreLink
    {
        $link = $this->claimSlot($store, $actor);

        // A finished link is the common case on a second click. Answering from the row costs
        // nothing and reaches Lynomia not at all.
        if ($link->state === LinkState::ACTIVE && $link->isProvisioned()) {
            return $link;
        }

        if ($link->link_mode !== LynomiaStoreLink::MODE_PROVISION) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'This store is being connected to an existing Lynomia workspace.'
            );
        }

        if ($link->state === LinkState::REVOKED) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                "This store's Lynomia connection was ended by support. Please contact support to restore it."
            );
        }

        $claim = $this->acquireLock($link);

        if ($claim === null) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_IN_PROGRESS,
                'This store is already being connected to Lynomia. Please wait a moment and refresh.'
            );
        }

        try {
            $this->resume($link, $store, $this->tenantOwner($actor));
        } finally {
            // Released whether the attempt succeeded or threw: a failed attempt that kept the
            // lock would block every retry until the claim went stale.
            $this->releaseLock($link, $claim);
        }

        return $link->refresh();
    }

    /**
     * Take (or recognise) this store's single link row.
     *
     * The unique index on store_id is the arbiter, not the SELECT: two concurrent callers can
     * both read "no row", and the one whose INSERT loses re-reads instead of failing. That
     * turns a race into a no-op, which is what a merchant double-clicking deserves.
     */
    private function claimSlot(Store $store, User $actor): LynomiaStoreLink
    {
        $existing = LynomiaStoreLink::forStore((int) $store->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return LynomiaStoreLink::create([
                'store_id' => (int) $store->id,
                'owner_user_id' => (int) $actor->creatorId(),
                'state' => LinkState::PENDING_VERIFICATION,
                'link_mode' => LynomiaStoreLink::MODE_PROVISION,
                'provisioning_stage' => ProvisioningStage::CLAIMED,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Lost the insert race. The winner's row is the right one to work on.
            $winner = LynomiaStoreLink::forStore((int) $store->id)->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    /**
     * Carry the link forward from wherever it is.
     *
     * Each step asks the ROW, not the stage, whether it still needs doing -- the id either is
     * or is not known, and that is the only fact that matters. The stage is written alongside
     * for an operator to read.
     */
    private function resume(LynomiaStoreLink $link, Store $store, User $owner): void
    {
        try {
            if ($link->lynomia_account_id === null) {
                $account = $this->adoptOrCreateAccount($store);

                $this->record($link, [
                    'lynomia_account_id' => $account['id'],
                    'lynomia_account_name' => $account['name'],
                    'provisioning_stage' => ProvisioningStage::ACCOUNT_CREATED,
                ]);
            }

            // THE WORKSPACE GETS ONE ADMINISTRATOR, AND IT IS THE TENANT OWNER -- not whoever
            // clicked the button. A staff member may well be the one setting this up, and
            // admitting them as the workspace's administrator because they happened to be
            // first would be the default escalation this design exists to refuse. They get
            // their own agent seat the moment they open the workspace, like anyone else.
            //
            // Called on every resume rather than tracked by a column: IdentityProvisioner is
            // idempotent end to end -- the address is deterministic, the upstream user resolve
            // and membership upsert are both no-ops the second time, and the local row is
            // arbitrated by a unique index -- so a second pass costs two cheap calls and
            // repairs an attempt that died between creating the user and recording it.
            $this->identities->identityFor($link, $owner);

            $this->record($link, [
                'provisioning_stage' => ProvisioningStage::COMPLETE,
                'state' => LinkState::ACTIVE,
                'verified_at' => $link->verified_at ?? now(),
                'last_error' => null,
                'last_synced_at' => now(),
            ]);

            // The workspace is live, so tell Lynomia what this merchant's package includes. Done
            // AFTER the state write rather than before: a failure here must not undo a workspace
            // that exists, and the grant is re-pushed on the next sync anyway.
            $this->seats->syncQuietly($link, $owner);
            // ...and ask the workspace to tell us when anything changes in it. Best effort for
            // the same reason: without it the inbox refreshes on demand, which is a slower
            // inbox rather than a broken one.
            $this->events->ensureSubscribed($link, $owner);
        } catch (QueryException $e) {
            // WRITING A DISCOVERED ID CAN ITSELF FAIL, and it must not escape as a raw
            // database error. The only way it does is the unique index on
            // lynomia_account_id -- another store adopted this workspace between the lookup
            // and the write -- which is a conflict to report, not a 500 to show a merchant.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'That Lynomia workspace is already connected to another store.'
            );
        } catch (LynomiaApiException $e) {
            // The row is KEPT, carrying both the reason and every id discovered so far.
            // Deleting it would let a retry storm create a workspace per attempt; keeping it
            // is what makes the next attempt a resumption.
            $this->record($link, [
                'last_error' => Str::limit($e->getMessage(), 480),
                'last_synced_at' => now(),
            ]);

            throw $e;
        }

        AuditLog::record('lynomia_workspace_provisioned', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'mode' => LynomiaStoreLink::MODE_PROVISION,
            'attempts' => (int) $link->provisioning_attempts,
        ]);
    }

    /**
     * Find this store's existing workspace, or make one.
     *
     * ASKING FIRST IS WHAT PREVENTS THE DUPLICATE. A create that timed out may well have
     * succeeded -- a read timeout is exactly the case where the server did all the work and
     * only the answer was lost -- and the new workspace's id is then known to Lynomia alone.
     * Every workspace this integration creates carries the store it serves in its custom
     * attributes, so the store can find its own again.
     *
     * TWO CANDIDATES IS A REFUSAL, NOT A CHOICE. If two workspaces claim the same store,
     * something has already gone wrong and picking one would silently strand the other, with
     * the merchant's conversations in it. An operator decides that, not this method.
     *
     * The lookup is scoped by Lynomia to this app's own permissibles, so it can neither see
     * nor adopt an account belonging to anyone else.
     *
     * @return array{id:int,name:string}
     */
    private function adoptOrCreateAccount(Store $store): array
    {
        $marker = $this->marker($store);

        $candidates = array_values(array_filter(
            $this->api->listAccounts($marker),
            fn (array $account): bool => $this->matchesMarker($account, $marker)
        ));

        if (count($candidates) > 1) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'More than one Lynomia workspace is registered to this store. Please contact support.'
            );
        }

        if (count($candidates) === 1) {
            return ['id' => $candidates[0]['id'], 'name' => $candidates[0]['name']];
        }

        return $this->api->createAccount($this->accountName($store), $marker);
    }

    /**
     * What identifies "this store, on this installation", on the Lynomia side.
     *
     * THE INSTALLATION HALF IS NOT DECORATION. Store ids are per-database autoincrements, so
     * two installations sharing a Platform App -- which is what restoring a production dump
     * into staging gives you -- collide on every id they have in common. With only the store
     * id, the lookup below does not fail closed: it finds exactly one candidate, adopts it,
     * and signs a staging merchant into a production workspace.
     *
     * @return array<string,int|string>
     */
    private function marker(Store $store): array
    {
        return [
            'store_syria_store_id' => (int) $store->id,
            'store_syria_installation' => $this->installationRef(),
        ];
    }

    /**
     * Re-check the marker on the client side.
     *
     * Lynomia filters too, and scopes the lookup to this app's own permissibles, but a
     * workspace is adopted on the strength of this match and an adoption is irreversible in
     * practice -- so the predicate the decision rests on is evaluated here, where it can be
     * read next to the decision. Compared as strings because a JSON number and a query-string
     * value are the same fact in two types.
     */
    private function matchesMarker(array $account, array $marker): bool
    {
        $attributes = $account['custom_attributes'] ?? [];

        foreach ($marker as $key => $value) {
            if (! is_array($attributes) || (string) ($attributes[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function installationRef(): string
    {
        return (string) config('lynomia.installation_ref', 'localhost');
    }

    /**
     * Take the in-flight claim, or report that someone else holds it.
     *
     * A CONDITIONAL UPDATE, NOT A READ FOLLOWED BY A WRITE. Two requests that both SELECT an
     * unlocked row both decide they may proceed; a single UPDATE whose WHERE clause carries
     * the condition is settled by the database, and exactly one of them affects a row. The
     * loser is told to wait.
     *
     * The claim EXPIRES. A process killed mid-provision cannot wedge the store forever: after
     * the stale window the next attempt takes the claim and resumes from the recorded ids.
     * That is safe to do while the original might still be running, because every step it
     * could repeat is either idempotent or guarded by the adopt-before-create lookup.
     */
    /** @return \Illuminate\Support\Carbon|null The claim taken, or null if somebody else holds it. */
    private function acquireLock(LynomiaStoreLink $link)
    {
        $staleBefore = now()->subMinutes(
            max(1, (int) config('lynomia.provisioning_lock_minutes', 5))
        );

        $claimedAt = now();

        $claimed = LynomiaStoreLink::query()
            ->whereKey($link->getKey())
            ->where(function ($query) use ($staleBefore) {
                $query->whereNull('provisioning_started_at')
                      ->orWhere('provisioning_started_at', '<', $staleBefore);
            })
            ->update([
                'provisioning_started_at' => $claimedAt,
                // THE INCREMENT IS LOAD-BEARING, NOT BOOKKEEPING. This lock reads the
                // affected-row count to decide who won, and the two databases do not count
                // the same thing: SQLite (tests) returns rows MATCHED, MySQL (production)
                // returns rows CHANGED, because PDO::MYSQL_ATTR_FOUND_ROWS is not set
                // anywhere in config/database.php. With only a timestamp in the SET list,
                // two attempts landing in the same whole second would write an identical
                // value, MySQL would report 0 changed, and the winner would be told someone
                // else holds the claim -- a spurious "already in progress" that no test on
                // SQLite could ever reproduce. A counter always changes, so the count always
                // reflects the WHERE clause. Do not remove it to "tidy" the SET list.
                'provisioning_attempts' => DB::raw('provisioning_attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        $link->refresh();

        return $claimedAt;
    }

    /**
     * Release only the claim this caller took.
     *
     * THE TIMESTAMP IS THE FENCING TOKEN, which is why it is passed back in rather than read
     * from the row. An attempt that overran its window does not know it was superseded: its
     * finally block still runs, and an unconditional release would clear the SUCCESSOR's
     * claim, letting a third request start while the second is still mid-flight -- two
     * attempts between the lookup and the create, which is the duplicate the lookup exists to
     * prevent. Two acquisitions can never share a timestamp, because a second one inside the
     * same second would have found the claim un-stale and not acquired at all.
     */
    private function releaseLock(LynomiaStoreLink $link, $claimedAt): void
    {
        LynomiaStoreLink::query()
            ->whereKey($link->getKey())
            ->where('provisioning_started_at', $claimedAt)
            ->update(['provisioning_started_at' => null, 'updated_at' => now()]);
    }

    /**
     * Persist a step and keep the in-memory row in step with it.
     *
     * forceFill because these are internal state transitions, not merchant input, and several
     * of the columns are deliberately absent from $fillable.
     */
    private function record(LynomiaStoreLink $link, array $attributes): void
    {
        $link->forceFill($attributes)->save();
    }

    /**
     * The person whose tenant this is, which is not necessarily the person who clicked.
     *
     * creatorId() answers it for both cases: an owner is their own creator, a staff member was
     * created by their owner. findOrFail rather than a fallback, because a tenant whose owner
     * record has gone is a broken installation and silently admitting the staff member instead
     * would hand them the administrator seat -- the exact escalation this avoids.
     */
    private function tenantOwner(User $actor): User
    {
        return (int) $actor->creatorId() === (int) $actor->id
            ? $actor
            : User::findOrFail($actor->creatorId());
    }

    private function accountName(Store $store): string
    {
        return Str::limit((string) ($store->name ?: "Store {$store->id}"), 60, '');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000/23505 across MySQL and Postgres; sqlite reports 23000 with "UNIQUE constraint".
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
