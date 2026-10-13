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
    public function provision(Store $store, User $owner): LynomiaStoreLink
    {
        $link = $this->claimSlot($store, $owner);

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

        if (in_array($link->state, [LinkState::SUSPENDED, LinkState::REVOKED], true)) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                "This store's Lynomia connection was ended by support. Please contact support to restore it."
            );
        }

        if (! $this->acquireLock($link)) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_IN_PROGRESS,
                'This store is already being connected to Lynomia. Please wait a moment and refresh.'
            );
        }

        try {
            $this->resume($link, $store);
        } finally {
            // Released whether the attempt succeeded or threw: a failed attempt that kept the
            // lock would block every retry until the claim went stale.
            $this->releaseLock($link);
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
    private function claimSlot(Store $store, User $owner): LynomiaStoreLink
    {
        $existing = LynomiaStoreLink::forStore((int) $store->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return LynomiaStoreLink::create([
                'store_id' => (int) $store->id,
                'owner_user_id' => (int) $owner->creatorId(),
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
    private function resume(LynomiaStoreLink $link, Store $store): void
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

            if ($link->lynomia_user_id === null) {
                // Idempotent by construction: the address is derived from the store id, and
                // upstream resolves an existing user for a known address rather than
                // creating a second one. Safe to repeat precisely BECAUSE the address cannot
                // belong to a real person -- see the class docblock.
                $user = $this->api->createUser(
                    $this->userName($store),
                    $this->serviceEmail($store),
                    // Never stored, never logged, never shown. Access is SSO only; this
                    // exists because the upstream endpoint requires a password field. A
                    // resumed attempt generates a different one, which changes nothing:
                    // upstream ignores it for a user that already exists.
                    Str::password(32)
                );

                $this->record($link, [
                    'lynomia_user_id' => $user['id'],
                    'provisioning_stage' => ProvisioningStage::USER_CREATED,
                ]);
            }

            // Idempotent upstream (find_or_initialize_by + update!), so it is re-sent on every
            // resume rather than tracked: re-attaching an attached member is a no-op, and
            // skipping it on a row whose stage was written but whose attach never landed
            // would leave a workspace nobody can reach.
            $this->api->attachAccountUser(
                (int) $link->lynomia_account_id,
                (int) $link->lynomia_user_id,
                'administrator'
            );

            $this->record($link, [
                'provisioning_stage' => ProvisioningStage::COMPLETE,
                'state' => LinkState::ACTIVE,
                'verified_at' => $link->verified_at ?? now(),
                'last_error' => null,
                'last_synced_at' => now(),
            ]);
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
        $marker = ['store_syria_store_id' => (int) $store->id];

        $candidates = array_values(array_filter(
            $this->api->listAccounts($marker),
            fn (array $account): bool => (int) ($account['custom_attributes']['store_syria_store_id'] ?? 0) === (int) $store->id
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
    private function acquireLock(LynomiaStoreLink $link): bool
    {
        $staleBefore = now()->subMinutes(
            max(1, (int) config('lynomia.provisioning_lock_minutes', 5))
        );

        $claimed = LynomiaStoreLink::query()
            ->whereKey($link->getKey())
            ->where(function ($query) use ($staleBefore) {
                $query->whereNull('provisioning_started_at')
                      ->orWhere('provisioning_started_at', '<', $staleBefore);
            })
            ->update([
                'provisioning_started_at' => now(),
                'provisioning_attempts' => DB::raw('provisioning_attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $link->refresh();

        return true;
    }

    private function releaseLock(LynomiaStoreLink $link): void
    {
        LynomiaStoreLink::query()
            ->whereKey($link->getKey())
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
