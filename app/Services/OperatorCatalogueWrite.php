<?php

namespace App\Services;

use App\Policies\UserPolicy;

/**
 * F206 — THE PLATFORM OPERATOR READS A MERCHANT'S CATALOGUE; THEY DO NOT WRITE IT.
 * =================================================================================================
 *
 * The owner's decision, 2026-10-30: *"the platform operator can read merchant catalogues and
 * diagnose their problems, but cannot edit merchants' products, their prices, their stock or their
 * variants, nor delete them, except by explicit and specific delegation."* And, where no suitable
 * delegation mechanism exists: *"block the write for now and document what delegation would
 * require."*
 *
 * WHY A SEPARATE SERVICE AND NOT A NARROWING OF SOMETHING THAT ALREADY EXISTS
 * -------------------------------------------------------------------------------------------------
 * Four existing chokepoints look like the right place and each one is wrong, measured rather than
 * guessed:
 *
 *   - `MerchantPermissions::names()` short-circuits to `['*']` for the operator. Removing that is
 *     the tempting one-line fix and it breaks **non-catalogue platform administration**, which the
 *     owner excluded by name: orders (`refuseWithoutPermission` on `Manage Order`/`Show Order`),
 *     coupons, analytics, the dashboard's withheld fields, `createOrderViaApi`, and the permission
 *     array the Flutter app is handed at login.
 *   - `resolveOwnedStore()` drops its `created_by` predicate for the operator and has **91 call
 *     sites in one file**, spanning stores, plans, settings, payment credentials, currencies,
 *     languages, orders, customers and delivery.
 *   - `ProductController::lacksProductPermission()` and `::productIsOutsideCurrentStore()` carry
 *     operator limbs, but the **read** paths the owner wants preserved share them with the writers
 *     — `attribute_combination_data` and `attribute_combination_edit` both go through them.
 *   - `UserPolicy::isOperator()` is consumed across eight files plus five policies and two Gates.
 *
 * So the narrowing has to be **per door**, at the write itself. That is also what makes it
 * auditable: `grep -rn 'OperatorCatalogueWrite::' app/` lists every door that is governed, and a
 * new catalogue writer that forgets to call it is visible as an absence rather than hidden inside a
 * helper's behaviour.
 *
 * WHAT THIS DOES NOT CLOSE, AND IT MUST BE SAID RATHER THAN IMPLIED
 * -------------------------------------------------------------------------------------------------
 * **A delegation mechanism already exists, and it is not a sound one.** `lab404/laravel-impersonate`
 * is vendored and wired: `StoreController::LoginWithAdmin` authorises `impersonate` through
 * `UserPolicy::impersonate` (operator only, refuses self, refuses another operator) and calls
 * `Impersonate::take()`. After that call `auth()->user()` **is the merchant**, so every gate in
 * this codebase — including this one — reads the merchant's identity and the merchant's
 * `current_store`. This service therefore blocks the operator's **direct** catalogue writes and
 * does not block operator-via-impersonation writes.
 *
 * That would be acceptable if impersonation were attributable. It is not: **no catalogue write is
 * audit-logged anywhere in this application.** `AuditLog::record` is called only for plan orders,
 * users and password resets; the `ActivityLog` writers are storefront-only. So a price edited while
 * impersonating is indistinguishable in the record from one the merchant made themselves. That is
 * precisely why impersonation is not the *"explicit and specific delegation"* the owner's decision
 * asks for, and why the honest outcome of F206 is to block the direct writes now and write down
 * what delegation would require. See the ledger row for that specification.
 *
 * HOW A DOOR USES THIS
 * -------------------------------------------------------------------------------------------------
 * Each door refuses in **its own existing idiom**, because the shapes are not interchangeable and
 * getting one wrong is a user-visible regression:
 *
 *   - `ProductController::update()` answers an `$.ajax` call whose success handler reads
 *     `data.flag` / `data.msg` without navigating, so it must return that array and never a
 *     redirect, or the merchant gets a blank toast.
 *   - The Admin API doors must use `ApiResponser::error()` — HTTP **200** with `status: 0` — or the
 *     403 that `refuseWithoutPermission()` already emits. **A new 401 forces a logout in the
 *     shipped Flutter app**, which is a user-visible regression on a path a client calls.
 *   - Plain web doors keep `redirect()->back()->with('error', ...)`.
 */
final class OperatorCatalogueWrite
{
    /**
     * May this principal NOT write a merchant's catalogue?
     *
     * True only for the platform operator acting as themself. While impersonating, the principal IS
     * the merchant and this returns false — see the class docblock, where that limit is stated
     * rather than hidden.
     */
    public static function refuses($user): bool
    {
        return $user !== null && UserPolicy::isOperator($user);
    }

    /**
     * The one message, so every door says the same thing to the merchant and to the operator.
     *
     * It names what to do instead rather than only refusing, because an operator meeting this is
     * usually trying to help a merchant and needs to know the sanctioned route.
     */
    public static function message(): string
    {
        return __('The platform operator can view a store\'s catalogue but cannot change its products, prices, stock or variants. Sign in to the store to make this change, or ask the merchant to make it.');
    }

    /**
     * The refusal shape the product editor's AJAX handler understands.
     *
     * `flag` and `msg` are the keys `resources/views/product/edit.blade.php` reads; `message` is
     * added because the two variant panels read `payload.message || payload.msg`.
     *
     * @return array<string,string>
     */
    public static function ajaxRefusal(): array
    {
        return [
            'flag' => 'error',
            'msg' => self::message(),
            'message' => self::message(),
        ];
    }
}
