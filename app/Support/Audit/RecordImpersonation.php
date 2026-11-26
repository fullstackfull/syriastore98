<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

/**
 * THE AUDIT TRAIL FOR "LOG IN AS", WHICH PREVIOUSLY RECORDED NOTHING.
 *
 * `StoreController::LoginWithAdmin` is gated (`UserPolicy::impersonate` admits only the platform
 * operator, refuses self and refuses another operator) and correctly bound, but it left no trace.
 * After it ran, every write in that session was attributed to the merchant, so a price, a refund or
 * a role changed by an operator reads in the trail as the merchant's own action. It is the one
 * action a tenant can neither take nor see, which is why it is recorded.
 *
 * THIS CHANGES NO PERMISSION. Who may impersonate is an owner decision and `UserPolicy` is not
 * touched by this listener.
 *
 * WHY A LISTENER RATHER THAN TWO LINES IN THE CONTROLLER. lab404 dispatches both events from
 * `ImpersonateManager`, so every impersonation is recorded -- including one taken through a door
 * this repository does not have yet. Two controller lines record only the doors someone remembered.
 *
 * THE ACTOR IS PASSED EXPLICITLY, AND THAT IS THE WHOLE POINT. Measured in the vendor source:
 * `ImpersonateManager::take()` dispatches `TakeImpersonation` AFTER `quietLogin($to)`, so at
 * listener time the session guard holds the IMPERSONATED MERCHANT. A row built from `auth()` would
 * read as the merchant having impersonated themselves -- an operator action attributed to the
 * tenant, which is precisely the mis-attribution this slice's sibling change exists to prevent.
 * `leave()` happens to dispatch after restoring the operator, so the guard would answer correctly
 * there; the explicit actor is used for both, because a property that holds by accident is not a
 * property.
 *
 * STORE ATTRIBUTION IS `null`, which `AuditAttribution` reaches on its own: the subject is a `User`
 * and `users` carries no `store_id`, so an operator with no store-bearing subject gets null rather
 * than the fabricated 1. A merchant may own several stores and naming one would be a guess. WHICH
 * merchant was impersonated is recorded exactly, in `subject_type` and `subject_id`.
 *
 * `ImpersonateManager` types both properties as `Authenticatable`, not `User`, so a non-`User`
 * principal is refused rather than written as one -- `audit_logs.user_id` means a `users` row.
 *
 * WHY THIS IS NOT IN `app/Listeners/`, WHICH IS WHERE A READER WILL LOOK FOR IT. Laravel 11's
 * `Application::configure()` calls `->withEvents()` unconditionally, and the framework
 * EventServiceProvider it registers has event DISCOVERY ON over `app/Listeners` -- so a class there
 * is registered by its handler signature IN ADDITION to the explicit `$listen` mapping, and runs
 * twice. This repository already records that on `AppServiceProvider` and on
 * `EnforceMarketingConsentOnMail`, which lives outside that directory for the same reason; I
 * re-derived it by writing this class there first and measuring two audit rows per impersonation.
 *
 * The two listeners that do sit in `app/Listeners/` are made idempotent instead. That option is not
 * open here: AN AUDIT ROW IS NOT IDEMPOTENT. Two rows is two rows, and a trail that double-counts
 * an operator's actions is worse than one that is absent, because it will be believed. So the class
 * sits beside `AuditAttribution`, its collaborator, and `ImpersonationIsAuditedTest` asserts both
 * that each event has exactly one listener and that this file is not under `app/Listeners/`.
 */
class RecordImpersonation
{
    public const STARTED = 'impersonation_started';

    public const ENDED = 'impersonation_ended';

    public function handleTake(TakeImpersonation $event): void
    {
        $this->record(self::STARTED, $event->impersonator, $event->impersonated);
    }

    public function handleLeave(LeaveImpersonation $event): void
    {
        $this->record(self::ENDED, $event->impersonator, $event->impersonated);
    }

    /**
     * One row, naming the actor explicitly.
     *
     * No values are recorded in `old_values`/`new_values`: nothing about either account changed,
     * and copying a user row into the trail would put e-mail addresses into a second place for no
     * reader's benefit. The action, the actor and the subject are the whole fact.
     */
    private function record(string $action, mixed $impersonator, mixed $impersonated): void
    {
        if (! $impersonator instanceof User || ! $impersonated instanceof User) {
            return;
        }

        AuditLog::record($action, $impersonated, [], [], $impersonator);
    }
}
