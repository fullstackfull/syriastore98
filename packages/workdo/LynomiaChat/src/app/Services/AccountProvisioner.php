<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Provisions a NEW Lynomia workspace for a store.
 *
 * This is the "create a new linked account" half of the approved flow. The other half --
 * attaching a workspace that already exists -- is AccountLinker, and the two are deliberately
 * separate classes because only one of them is allowed to call createUser().
 *
 * WHY THE LYNOMIA USER IS NOT CREATED WITH THE MERCHANT'S REAL EMAIL.
 *
 * Upstream, POST /platform/api/v1/users resolves `User.from_email(...) || User.new(...)` and
 * then grants the calling app a permissible on whatever it resolved. Handed an address that
 * already belongs to an independently-registered Lynomia customer, it does not fail and it
 * does not create anything -- it hands this app control of THEIR account, after which
 * /users/{id}/login mints a working session for them.
 *
 * Store Syria cannot detect that case before calling: a platform token can only read accounts
 * it already holds a permissible for, so "does this email exist in Lynomia?" is a question
 * this app is not allowed to ask. Provisioning with the merchant's real address is therefore
 * exactly the "link by email similarity alone" the approved rules forbid -- it just fails
 * silently in the merchant's favour instead of visibly.
 *
 * So a provisioned workspace gets a deterministic, store-scoped service address that cannot
 * collide with a real person's account. The merchant never signs in with it: access is the
 * one-way SSO link, which is the only access path this integration offers. A merchant who
 * wants their own Lynomia identity on the workspace uses the existing-account flow, which
 * requires administrative approval precisely because it touches an identity we cannot verify
 * from here.
 */
class AccountProvisioner
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /**
     * Provision, or return the existing link untouched.
     *
     * IDEMPOTENT BY DATABASE CONSTRAINT, NOT BY CHECK-THEN-WRITE. The early return below is a
     * convenience, not the guarantee: two concurrent requests both read "no link" and both
     * proceed. The unique index on store_id is what actually decides, and the loser is
     * reported as a conflict rather than silently creating a second Lynomia workspace.
     */
    public function provision(Store $store, User $owner): LynomiaStoreLink
    {
        $existing = LynomiaStoreLink::forStore((int) $store->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $ownerId = (int) $owner->creatorId();

        // Claim the row BEFORE any remote call. If the claim loses the race there is nothing
        // to roll back on the Lynomia side, which is the cheap ordering: a duplicate row is
        // recoverable, a duplicate workspace is not.
        try {
            $link = LynomiaStoreLink::create([
                'store_id' => (int) $store->id,
                'owner_user_id' => $ownerId,
                'state' => LinkState::PENDING_VERIFICATION,
                'link_mode' => LynomiaStoreLink::MODE_PROVISION,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new LynomiaApiException(
                    LynomiaApiException::KIND_CONFLICT,
                    'This store is already being connected to Lynomia.'
                );
            }

            throw $e;
        }

        try {
            $account = $this->api->createAccount(
                $this->accountName($store),
                ['store_syria_store_id' => (int) $store->id]
            );

            $user = $this->api->createUser(
                $this->userName($store),
                $this->serviceEmail($store),
                // Never stored, never logged, never shown. Access is SSO only; this exists
                // because the upstream endpoint requires a password field.
                Str::password(32)
            );

            $this->api->attachAccountUser($account['id'], $user['id'], 'administrator');

            $link->forceFill([
                'lynomia_account_id' => $account['id'],
                'lynomia_user_id' => $user['id'],
                'lynomia_account_name' => $account['name'],
                'state' => LinkState::ACTIVE,
                'verified_at' => now(),
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();
        } catch (LynomiaApiException $e) {
            // The claim row is KEPT, carrying the reason. Deleting it would let a retry storm
            // create a workspace per attempt; keeping it means the next attempt resumes
            // against the same row and an operator can see what failed.
            $link->forceFill([
                'last_error' => Str::limit($e->getMessage(), 480),
                'last_synced_at' => now(),
            ])->save();

            throw $e;
        }

        AuditLog::record('lynomia_workspace_provisioned', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'mode' => LynomiaStoreLink::MODE_PROVISION,
        ]);

        return $link->refresh();
    }

    private function accountName(Store $store): string
    {
        return Str::limit((string) ($store->name ?: "Store {$store->id}"), 60, '');
    }

    private function userName(Store $store): string
    {
        return Str::limit((string) ($store->name ?: "Store {$store->id}"), 50, '') . ' Owner';
    }

    /**
     * A deterministic address that is unique per store and cannot belong to a real person.
     *
     * Deterministic so a retry resolves the same Lynomia user rather than creating a second
     * one. The domain is a reserved example domain by default -- nothing is ever delivered to
     * it, which is correct: this identity exists to own the workspace, not to receive mail.
     */
    private function serviceEmail(Store $store): string
    {
        return sprintf('store-%d@%s', $store->id, 'stores.storesyria.invalid');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000/23505 across MySQL and Postgres; sqlite reports 23000 with "UNIQUE constraint".
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
