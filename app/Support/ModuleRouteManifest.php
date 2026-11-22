<?php

namespace App\Support;

/**
 * P1-SEC-04 -- the classification of every WorkDo module route that carries NO authentication middleware.
 *
 * Measured with tests/Support/Security/bin/route-census.php (every module loaded through the production loader, 70 of them): a module declares
 * its routes in `Route::middleware(['web'])` groups, and shopper-facing endpoints and merchant screens share them. Each such route is exactly
 * one of:
 *
 *   ADMINISTRATIVE        a merchant/operator screen or writer. App\Http\Middleware\ModuleAdministrativeRouteGuard makes it require a signed-in
 *                         `web` user (and, for the ownership rules, the right tenant). Rules are documented there.
 *   ANONYMOUS_BY_DESIGN   meant to be reachable without a login: a storefront page or action bound to a public {storeSlug}, a payment-gateway
 *                         return/notify URL, a webhook that carries its own credential, an OAuth redirect, or a route another host guard owns
 *                         (named in the value). Being listed here is a CLASSIFICATION, not a clean bill: the entries marked `storefront` still
 *                         write rows for an anonymous shopper, bound to the slug's tenant.
 *
 * tests/Feature/Security/RouteCensusGateTest re-runs the census and FAILS when a module route with no authentication middleware is in neither
 * table -- so a module update that adds an unclassified route is a red build -- and when a listed key no longer exists (a stale entry would hide a
 * future route that reuses the key).
 *
 * Keys are `METHOD uri` exactly as the router reports them (HEAD is folded into GET).
 */
final class ModuleRouteManifest
{
    /** @var array<string,string> `METHOD uri` => rule */
    public const ADMINISTRATIVE = [
        'POST Imagebadge-store' => 'auth',
        'POST Wizzchat-form' => 'auth',
        'POST admin_email_template_lang/{id}' => 'owner:email-template',
        'GET admin_email_template_lang/{id}/{lang?}' => 'owner:email-template',
        /*
         * P1-SEC-87 -- THE APPOINTMENT ROWS, THE SAME SHAPE AS THE CONTACTS P1-SEC-84 CLOSED.
         *
         * Found by following P1-SEC-84's own "still unexamined" note rather than by a new census:
         * these sit in the SAME Marketing route group and looked like the same pattern.
         *
         * `AppointmentController` (vendored, not edited) acts on a row by bare id in four
         * handlers: `destroy()` checks only the 'Delete Appointment' PERMISSION, `note_store()`
         * only 'Edit Appointment' before writing `status` and `note`, `add_note()` reads the row,
         * and `getAppointmentDetails()` CHECKS NOTHING AT ALL -- `find($id)` and then renders the
         * calendar modal, which shows a customer's name, contact details and appointment time.
         * That last one is the worst of the four and the reason this is not merely tidy-up.
         *
         * The module itself knows these rows are tenant-scoped: `index()` and
         * `getCalenderAllData()` both read
         * `where('store_id', $id)->where('created_by', auth()->user()->creatorId())`. Nothing else
         * supplied the predicate -- `appointment_deatails` (the typo is the vendored migration's)
         * carries `store_id` and `created_by`, and `AppointmentDeatail` is absent from
         * `config/tenancy.php` `scoped_models`.
         *
         * `appointments/delete/{id}` is `Route::any`, hence six verbs. The four
         * `appointments/{appointment}` keys below take the bound parameter's name instead, and
         * three of them are DEAD: `show()`, `update()` and `edit()` do not exist on the controller,
         * so the resource routes pointing at them throw. They are listed anyway, for the reason
         * P1-SEC-84 listed `contacts/update`: a rule must not depend on a method staying absent.
         *
         * `get_appointment_data` (six verbs) IS DELIBERATELY NOT LISTED, and it is worth saying why
         * rather than leaving a gap that looks like an oversight. P1-SEC-87 first recorded it as a
         * body-keyed door needing its own guard. MEASURED AFTERWARDS, THAT WAS WRONG on both
         * counts:
         *
         *   - `$request->id` is used as a STORE id and not an appointment id, and in BOTH branches
         *     it is ANDed with a predicate derived from the authenticated principal:
         *     `where('store_id', $id)->where('created_by', auth()->user()->creatorId())`. A caller
         *     naming another tenant's store gets `store_id = theirs AND created_by = mine`, which
         *     is empty. The caller-supplied value never stands alone, which is the whole of the
         *     objection to such predicates.
         *   - `$objUser->current_business` is NOT a column on `users` -- `Schema::hasColumn()` is
         *     false and the only column matching is `current_store`. So it resolves to null, the
         *     handler takes its `else` branch, and that branch is `where('created_by', creatorId())`:
         *     scoping still holds through `created_by`. What is lost is the per-STORE narrowing, so
         *     a merchant with several stores sees their own appointments across all of them rather
         *     than only the current one. A correctness and interface defect, not a tenant leak, and
         *     not fixed here because changing a vendored handler's behaviour needs a first-party
         *     vehicle and an owner who wants that behaviour changed.
         *
         * ONE LEAD REMAINS, stated as a lead: `creatorId()` answers with the OPERATOR's id for a
         * principal the operator roots -- staff they created, or a merchant who changed their own
         * `type`. Such a caller would read the operator's own appointments here. That is the same
         * disposition `RolePolicy::tenantOwner()` records for operator-created staff and which
         * P1-SEC-83 fixed only where the ceiling ROSE, so it needs its own measurement rather than
         * a reflex.
         */
        'POST appointment-note-store/{id?}' => 'store-row:appointment_deatails:id',
        'GET appointment-note/{id?}' => 'store-row:appointment_deatails:id',
        'DELETE appointments/delete/{id}' => 'store-row:appointment_deatails:id',
        'GET appointments/delete/{id}' => 'store-row:appointment_deatails:id',
        'OPTIONS appointments/delete/{id}' => 'store-row:appointment_deatails:id',
        'PATCH appointments/delete/{id}' => 'store-row:appointment_deatails:id',
        'POST appointments/delete/{id}' => 'store-row:appointment_deatails:id',
        'PUT appointments/delete/{id}' => 'store-row:appointment_deatails:id',
        'DELETE appointments/{appointment}' => 'store-row:appointment_deatails:appointment',
        'GET appointments/{appointment}' => 'store-row:appointment_deatails:appointment',
        'PATCH appointments/{appointment}' => 'store-row:appointment_deatails:appointment',
        'PUT appointments/{appointment}' => 'store-row:appointment_deatails:appointment',
        'GET appointments/{appointment}/edit' => 'store-row:appointment_deatails:appointment',
        'GET get-appointment-detail/{id}' => 'store-row:appointment_deatails:id',
        'POST auction-order-assign' => 'auth',
        'POST auction-order-return' => 'auth',
        'POST auction-order-status-change/{id}' => 'auth',
        'GET auction-order-view/{id}' => 'auth',
        'POST auth/duo/verify' => 'auth',
        'GET authduologin' => 'auth',
        'DELETE badge-setting-form' => 'auth',
        'GET badge-setting-form' => 'auth',
        'OPTIONS badge-setting-form' => 'auth',
        'PATCH badge-setting-form' => 'auth',
        'POST badge-setting-form' => 'auth',
        'PUT badge-setting-form' => 'auth',
        'POST badge-store' => 'auth',
        'POST barcode-settings' => 'settings:store',
        'GET barcode-type' => 'auth',
        'POST bitbucket-settings' => 'settings:global',
        'POST boost-sales-setting' => 'settings:store',
        'GET boostsales' => 'auth',
        'POST boostsales' => 'auth',
        'GET boostsales/create' => 'auth',
        'DELETE boostsales/{boostsale}' => 'auth',
        'GET boostsales/{boostsale}' => 'auth',
        'PATCH boostsales/{boostsale}' => 'auth',
        'PUT boostsales/{boostsale}' => 'auth',
        'GET boostsales/{boostsale}/edit' => 'auth',
        'GET campaigns/applycouponpromote' => 'auth',
        'GET campaigns/campaigns' => 'auth',
        'POST campaigns/campaigns' => 'auth',
        'DELETE campaigns/campaigns-DPO/create' => 'auth',
        'OPTIONS campaigns/campaigns-DPO/create' => 'auth',
        'PATCH campaigns/campaigns-DPO/create' => 'auth',
        'POST campaigns/campaigns-DPO/create' => 'auth',
        'PUT campaigns/campaigns-DPO/create' => 'auth',
        'GET campaigns/campaigns-analytics/{id}' => 'owner:campaign-route',
        'DELETE campaigns/campaigns-braintree-payment' => 'auth',
        'OPTIONS campaigns/campaigns-braintree-payment' => 'auth',
        'PATCH campaigns/campaigns-braintree-payment' => 'auth',
        'POST campaigns/campaigns-braintree-payment' => 'auth',
        'PUT campaigns/campaigns-braintree-payment' => 'auth',
        /*
         * P1-SEC-86 -- `auth` IS THE RIGHT CLASSIFICATION HERE, AND IT IS NOT THE WHOLE STORY.
         *
         * `campaignsCost()` deletes `cost_setting` rows by `whereIn('id', $deletedIds)` and updates
         * them by `find($costSetting['id'])`, both from BODY input and neither with an ownership
         * predicate. The update is a TRANSFER rather than an edit: `created_by` is assigned from
         * the caller BEFORE `update()`, so naming another principal's row hands it over.
         *
         * The ownership check is `App\Http\Middleware\CampaignsCostOwnershipGuard`, appended to
         * the `web` group, which keys off its own `TARGETS` rather than a rule here. A `guarded:`
         * rule would be WRONG IN THIS TABLE: `administrativeRule()` reads only `ADMINISTRATIVE`,
         * and `ModuleAdministrativeRouteGuard`'s `match` has no `guarded:` arm, so such a rule
         * falls to `default => false` and denies every non-operator -- measured, when a first
         * version of this change did exactly that and broke a merchant's own create and delete.
         * `guarded:` belongs in `ANONYMOUS_BY_DESIGN` below, where it records that a named guard
         * protects a route carrying no authentication.
         *
         * AND THIS ROUTE CARRIES NO `Authenticate` MIDDLEWARE EITHER -- measured, the census
         * reports `authenticated: false`, because the Campaigns group is `['web']` plus
         * `PlanModuleCheck:Campaigns` and nothing else. The rule below is what authenticates it:
         * `ModuleAdministrativeRouteGuard` refuses a null `auth('web')` user for an `auth` rule,
         * and it runs at stack position 21, BEFORE `CampaignsCostOwnershipGuard` at 23. So the
         * ownership guard's own anonymous limb is unreachable today and is defence in depth; a
         * mutation removing it survives for that reason, which its test records rather than
         * hides.
         *
         * NOT `store-row:`, which reads a ROUTE parameter and compares `store_id`: these ids are in
         * the body and `cost_setting` has no `store_id`. NOT `operator` either, which the sibling
         * `campaigns-system-setup` carries and which was the first thing that came to mind: these
         * are per-principal pricing tiers keyed on `created_by` alone, so an operator-only rule
         * would take every merchant's own tiers away from them.
         */
        'DELETE campaigns/campaigns-cost' => 'auth',
        'GET campaigns/campaigns-cost' => 'auth',
        'OPTIONS campaigns/campaigns-cost' => 'auth',
        'PATCH campaigns/campaigns-cost' => 'auth',
        'POST campaigns/campaigns-cost' => 'auth',
        'PUT campaigns/campaigns-cost' => 'auth',
        'POST campaigns/campaigns-costing' => 'auth',
        'POST campaigns/campaigns-enable' => 'owner:campaign',
        'DELETE campaigns/campaigns-pay-with/DPO' => 'auth',
        'OPTIONS campaigns/campaigns-pay-with/DPO' => 'auth',
        'PATCH campaigns/campaigns-pay-with/DPO' => 'auth',
        'POST campaigns/campaigns-pay-with/DPO' => 'auth',
        'PUT campaigns/campaigns-pay-with/DPO' => 'auth',
        'DELETE campaigns/campaigns-pay-with/Powertranz' => 'auth',
        'OPTIONS campaigns/campaigns-pay-with/Powertranz' => 'auth',
        'PATCH campaigns/campaigns-pay-with/Powertranz' => 'auth',
        'POST campaigns/campaigns-pay-with/Powertranz' => 'auth',
        'PUT campaigns/campaigns-pay-with/Powertranz' => 'auth',
        'DELETE campaigns/campaigns-payu-payment/{pay}' => 'auth',
        'OPTIONS campaigns/campaigns-payu-payment/{pay}' => 'auth',
        'PATCH campaigns/campaigns-payu-payment/{pay}' => 'auth',
        'POST campaigns/campaigns-payu-payment/{pay}' => 'auth',
        'PUT campaigns/campaigns-payu-payment/{pay}' => 'auth',
        'POST campaigns/campaigns-product' => 'tenant-store:store_id',
        'GET campaigns/campaigns-settings' => 'auth',
        'GET campaigns/campaigns-status/{id}/{response}' => 'operator',
        'GET campaigns/campaigns-system-setup' => 'operator',
        'POST campaigns/campaigns-system-setup' => 'operator',
        'GET campaigns/campaigns-system-setup/create' => 'operator',
        'DELETE campaigns/campaigns-system-setup/{campaigns_system_setup}' => 'operator',
        'GET campaigns/campaigns-system-setup/{campaigns_system_setup}' => 'operator',
        'PATCH campaigns/campaigns-system-setup/{campaigns_system_setup}' => 'operator',
        'PUT campaigns/campaigns-system-setup/{campaigns_system_setup}' => 'operator',
        'GET campaigns/campaigns-system-setup/{campaigns_system_setup}/edit' => 'operator',
        'GET campaigns/campaigns-view/{id}' => 'operator',
        'GET campaigns/campaigns/create' => 'auth',
        'DELETE campaigns/campaigns/{campaign}' => 'auth',
        'GET campaigns/campaigns/{campaign}' => 'auth',
        'PATCH campaigns/campaigns/{campaign}' => 'auth',
        'PUT campaigns/campaigns/{campaign}' => 'auth',
        'GET campaigns/campaigns/{campaign}/edit' => 'auth',
        'POST club-point-setting' => 'settings:store',
        /*
         * P1-SEC-84 -- THE MARKETING CONTACT ROWS, WHICH WERE REACHABLE BY ID FOR ANY TENANT.
         *
         * `ContactController` (vendored, not edited) acts on a row by bare id in four handlers and
         * applies a tenant predicate in none of them: `destroy()` checks only the
         * 'Delete Marketing Contact' PERMISSION, `edit()` checks NOTHING AT ALL and renders the
         * row, `note_store()` checks only 'Edit Marketing Contact' and writes `status` and `note`,
         * and `update()` overwrites the four text fields.
         *
         * Nothing else supplied the predicate. `MarketingContact` is ABSENT from
         * `config/tenancy.php` `scoped_models` and declares only `HasFactory, Cachable` -- no
         * `BelongsToStore`, no `StoreScope` -- so `find($id)` reaches every tenant's rows. And the
         * only Marketing-contact keys in this file were the six STOREFRONT verbs of
         * `marketing/contacts/store/{slug}`, the public submit form, so `administrativeRule()`
         * returned null here and the guard passed through before it read the user.
         *
         * `store-row:marketing_contacts:id` IS THE MODULE'S OWN QUESTION, not a convenient one:
         * it compares the row's `store_id` to the caller's `current_store`, which is exactly what
         * `MarketingContact::cardContactData()` does (`where('store_id', getCurrentStore())`). The
         * writer sets `store_id` (`ContactController::store()`, from a Store matched by slug AND
         * id), so existing rows carry one and this does not fail-close legitimate access.
         *
         * The `{id?}` routes deny when the id is absent, which costs nothing real: with a null id
         * `add_note()` resolves no row and `note_store()` would throw on it.
         *
         * `POST contacts/update/{id}` IS CURRENTLY INERT and is listed anyway. Its signature is
         * `update(Request, Contacts $Contacts, $id)` where `Contacts` is `Melipayamak\Contacts`,
         * whose constructor takes two required untyped arguments, so the container cannot build it
         * and the route throws for EVERY caller including the row's owner -- measured, after a
         * positive control refused to pass. The rule must not depend on a vendor constructor
         * signature: if that stray import is ever removed, the door goes live with no warning.
         *
         * NOT given a rule, deliberately: `GET contacts/store/show{id}` routes to `index()`, which
         * ignores the id entirely and renders the list, so it is not a row door.
         *
         * STILL UNEXAMINED, so not mistaken for covered: the `contacts/{contact}` resource family
         * and `appointments/delete/{id}` / `appointment-note-store/{id?}` in this same route group
         * are the same SHAPE and have had no measurement of their own.
         */
        'POST contact-note-store/{id?}' => 'store-row:marketing_contacts:id',
        'GET contact-note/{id?}' => 'store-row:marketing_contacts:id',
        'DELETE contacts/delete/{id}' => 'store-row:marketing_contacts:id',
        'GET contacts/delete/{id}' => 'store-row:marketing_contacts:id',
        'OPTIONS contacts/delete/{id}' => 'store-row:marketing_contacts:id',
        'PATCH contacts/delete/{id}' => 'store-row:marketing_contacts:id',
        'POST contacts/delete/{id}' => 'store-row:marketing_contacts:id',
        'PUT contacts/delete/{id}' => 'store-row:marketing_contacts:id',
        'GET contacts/edit/{id}' => 'store-row:marketing_contacts:id',
        'POST contacts/update/{id}' => 'store-row:marketing_contacts:id',
        'POST count-down-setting' => 'settings:store',
        /*
         * P1-SEC-89 (a) -- A CUSTOMER'S LOYALTY HISTORY, KEYED ON THE CUSTOMER'S ID.
         *
         * `RewardClubPointController::clubPointHistory($customerId)` runs
         * `RewardClubPoint::with('orderData')->where('customer_id', $customerId)->get()` with no
         * permission check and no store predicate, and `RewardClubPoint` is NOT one of the eleven
         * `config/tenancy.php` `scoped_models` and declares only `HasFactory, Cachable`. So the
         * points, used_points, reward_price, order_id and product_id of SOMEBODY ELSE'S shopper
         * were rendered.
         *
         * EASY TO MISS, AND WORTH SAYING WHY: `App\Models\Customer` IS scoped, so the
         * `Customer::find($customerId)` beside that query returns null for another tenant's
         * customer and the page renders with no customer at all -- it looks broken rather than
         * leaky, while the figures beside it are real and foreign.
         *
         * THE RULE NAMES `customers`, NOT `reward_club_points`, and that is the whole point of
         * classifying this door separately from the module's `show()` below: the parameter is a
         * CUSTOMER id, so the row whose ownership decides the request is the customer's.
         * `store-row:reward_club_points:customerId` would compare a customer id against
         * reward-row ids and so admit or refuse the wrong rows -- a rule that is wrong in a way
         * that still looks like a control.
         */
        'GET customer-club-point-history/{customerId}' => 'store-row:customers:customerId',
        'POST coupencode/Status/store' => 'auth',
        'POST couponemail-settings' => 'settings:global',
        'GET couponlist' => 'auth',
        'POST couponlist' => 'auth',
        'GET couponlist/create' => 'auth',
        'DELETE couponlist/{couponlist}' => 'auth',
        'GET couponlist/{couponlist}' => 'auth',
        'PATCH couponlist/{couponlist}' => 'auth',
        'PUT couponlist/{couponlist}' => 'auth',
        'GET couponlist/{couponlist}/edit' => 'auth',
        'GET customer-email/email_template_lang/{id}/{lang?}' => 'owner:email-template',
        'PUT customer-email/update/email_template/{id}' => 'owner:email-template',
        // P1-SEC-68: the seven AUTHENTICATED Helpdesk ticket routes -- edit-ticket, update-ticket and ticket-reply by the encrypted ticket NUMBER;
        // delete-ticket, ticket-conversion-store and ticket-note-store by the ROW id; get-user-data (name + e-mail of any user id) for the operator alone,
        // because the only form that calls it renders its company select for the operator. The table is sorted by uri, so they are not together.
        'DELETE delete-ticket/{ticketId}' => 'owner:helpdesk-ticket-row',
        'DELETE digital-products/{id}/delete' => 'auth',
        'GET duo2fa' => 'auth',
        'POST duo2fa' => 'auth',
        'POST duo2fa-setting' => 'owner:duo-setting',
        'GET duo2fa/create' => 'auth',
        'DELETE duo2fa/{duo2fa}' => 'auth',
        'GET duo2fa/{duo2fa}' => 'auth',
        'PATCH duo2fa/{duo2fa}' => 'auth',
        'PUT duo2fa/{duo2fa}' => 'auth',
        'GET duo2fa/{duo2fa}/edit' => 'auth',
        'GET edit-ticket/{ticketId}' => 'owner:helpdesk-ticket-link',
        'GET enquiry-email/email_template_lang/{id}/{lang?}' => 'owner:email-template',
        'PUT enquiry-email/update/email_template/{id}' => 'owner:email-template',
        'POST facebooklogin-setting' => 'settings:global',
        'POST free-shipping-popup-setting' => 'settings:store',
        'GET freeshippingpopup' => 'auth',
        'POST freeshippingpopup' => 'auth',
        'GET freeshippingpopup/create' => 'auth',
        'DELETE freeshippingpopup/{freeshippingpopup}' => 'auth',
        'GET freeshippingpopup/{freeshippingpopup}' => 'auth',
        'PATCH freeshippingpopup/{freeshippingpopup}' => 'auth',
        'PUT freeshippingpopup/{freeshippingpopup}' => 'auth',
        'GET freeshippingpopup/{freeshippingpopup}/edit' => 'auth',
        'POST frequently-product-setting' => 'settings:store',
        'DELETE frequently-product-setting-form' => 'auth',
        'GET frequently-product-setting-form' => 'auth',
        'OPTIONS frequently-product-setting-form' => 'auth',
        'PATCH frequently-product-setting-form' => 'auth',
        'POST frequently-product-setting-form' => 'auth',
        'PUT frequently-product-setting-form' => 'auth',
        'GET frequentlyproduct' => 'auth',
        'POST frequentlyproduct' => 'auth',
        'GET frequentlyproduct/create' => 'auth',
        'DELETE frequentlyproduct/{frequentlyproduct}' => 'auth',
        'GET frequentlyproduct/{frequentlyproduct}' => 'auth',
        'PATCH frequentlyproduct/{frequentlyproduct}' => 'auth',
        'PUT frequentlyproduct/{frequentlyproduct}' => 'auth',
        'GET frequentlyproduct/{frequentlyproduct}/edit' => 'auth',
        'POST get-user-data' => 'operator',
        'POST github-settings' => 'settings:global',
        'POST googlelogin-setting' => 'settings:global',
        'GET helpdesk-ticket-show/{ticketId}' => 'owner:helpdesk-ticket-link',
        'POST helpdesk-ticket/{ticketId}' => 'owner:helpdesk-ticket',
        'POST hubspot/setting/store' => 'settings:global',
        /*
         * P1-SEC-85 -- THE PLATFORM'S OWN HOME PAGE, WHICH ANY MERCHANT COULD REWRITE.
         *
         * `LandingPage\...\HomeController` states its own audience in `index()`:
         * `if (\Auth::user()->type == 'super admin') { ...the screen... } else { 'Permission
         * Denied.' }`. A grep for EVERY authorization reference in that file -- `isAbleTo`,
         * `'super admin'`, `authorize`, `Gate::`, `__construct` -- returns exactly ONE line, that
         * one, inside `index()`. So `store()`, `update()`, `destroy()`, `create()`, `edit()` and
         * `show()` had none: the READ was gated and the WRITES were not.
         *
         * NOT a tenancy defect, and that distinction is why the rule is `operator` and not
         * `store-row:`: `landing_page_settings` is `id`, `name` (unique), `value`, `timestamps` --
         * no `store_id`, no `created_by` -- so it is a single GLOBAL key-value table by design and
         * no tenant predicate is missing. The rows are the content of the platform's PUBLIC home
         * page, which is the operator's to set.
         *
         * `store()` writes a HARD-CODED list of ten key names, so this is not an arbitrary-name
         * settings writer either and `settings:global` would be the wrong rule as well as the
         * wrong table.
         *
         * All eight verbs of `Route::resource('homesection', ...)` are listed, `GET homesection`
         * included: the operator passes the guard and then the handler's own check, and a
         * non-operator is now refused by the guard instead of by the handler -- the same outcome,
         * earlier, and it stops the read and the write disagreeing again.
         *
         * The group is `['auth','web','xss','setlocate','verified']` with NO `PlanModuleCheck`, and
         * `verified` is a pass-through unless the operator turns e-mail verification on, so `auth`
         * was the only thing these routes asked for. The guard itself WAS already on the request
         * (measured in the census middleware stack); it simply had no rule.
         *
         * LandingPage loads UNCONDITIONALLY -- `PackageServiceProvider::register()` carries
         * `$staticAddons = ['LandingPage', 'LynomiaChat']` and the dynamic loader force-appends it
         * again -- so these routes exist on every install, not only where an add_on_managers row
         * does.
         *
         * STILL OPEN and recorded rather than implied: the same handler's two upload doors
         * (`LandingPageSetting::upload_file()` / `::keyWiseUpload_file()`), which
         * `PlatformUploadPolicy` explicitly disclaims, are a separate item.
         */
        'GET homesection' => 'operator',
        'POST homesection' => 'operator',
        'GET homesection/create' => 'operator',
        'DELETE homesection/{homesection}' => 'operator',
        'GET homesection/{homesection}' => 'operator',
        'PATCH homesection/{homesection}' => 'operator',
        'PUT homesection/{homesection}' => 'operator',
        'GET homesection/{homesection}/edit' => 'operator',
        'GET invoices/preview/{template}/{color}' => 'auth',
        'POST linkedin-setting' => 'settings:global',
        'POST lottery-order-return' => 'auth',
        'POST lottery-order-status-change/{id}' => 'auth',
        // P1-SEC-64b: the SEVENTH template door. LotteryPrizes' handlers read and rewrite EmailTemplate::where('id', $id) with no ownership check, behind only
        // `auth` and its plan check -- the write-up said seven module copies were guarded; this table named six.
        'GET lottery_email_lang/{id}/{lang?}' => 'owner:email-template',
        'PUT lottery_email_lang/{id}' => 'owner:email-template',
        'POST mail-settings' => 'auth',
        'GET mail-settings/create' => 'auth',
        'DELETE marketing/store/domain-store/{id}' => 'settings:global',
        'GET marketing/store/domain-store/{id}' => 'settings:global',
        'OPTIONS marketing/store/domain-store/{id}' => 'settings:global',
        'PATCH marketing/store/domain-store/{id}' => 'settings:global',
        'POST marketing/store/domain-store/{id}' => 'settings:global',
        'PUT marketing/store/domain-store/{id}' => 'settings:global',
        'GET marketplacelist' => 'auth',
        'POST marketplacelist' => 'auth',
        'GET marketplacelist/create' => 'auth',
        'DELETE marketplacelist/{marketplacelist}' => 'auth',
        'GET marketplacelist/{marketplacelist}' => 'auth',
        'PATCH marketplacelist/{marketplacelist}' => 'auth',
        'PUT marketplacelist/{marketplacelist}' => 'auth',
        'GET marketplacelist/{marketplacelist}/edit' => 'auth',
        'DELETE order-review-store/{Id}' => 'store-row:order_reviews:Id',
        'GET order-review/email_template_lang/{id}/{lang?}' => 'owner:email-template',
        'POST order-review/update/email_template/{id}' => 'owner:email-template',
        'POST order-review/update/email_template_status/{id}' => 'owner:email-template',
        'POST outlooklogin-setting' => 'settings:global',
        'POST partial-payment-settings' => 'settings:store',
        'GET partialpayments' => 'auth',
        'POST partialpayments' => 'auth',
        'GET partialpayments/create' => 'auth',
        'DELETE partialpayments/{partialpayment}' => 'auth',
        'GET partialpayments/{partialpayment}' => 'auth',
        'PATCH partialpayments/{partialpayment}' => 'auth',
        'PUT partialpayments/{partialpayment}' => 'auth',
        'GET partialpayments/{partialpayment}/edit' => 'auth',
        'GET payment-email/email_template_lang/{id}/{lang?}' => 'owner:email-template',
        'PUT payment-email/update/email_template/{id}' => 'owner:email-template',
        'POST payment-status-change/{id}' => 'auth',
        'GET preorder-setting/email_template_lang/{id}/{lang?}' => 'owner:email-template',
        'PUT preorder-setting/update/email_template/{id}' => 'owner:email-template',
        'GET productcatelog' => 'auth',
        'GET productcatelog/create' => 'auth',
        'DELETE productcatelog/{productcatelog}' => 'auth',
        'GET productcatelog/{productcatelog}' => 'auth',
        'PATCH productcatelog/{productcatelog}' => 'auth',
        'PUT productcatelog/{productcatelog}' => 'auth',
        'GET productcatelog/{productcatelog}/edit' => 'auth',
        'POST productpricing/rulesCondition' => 'settings:store',
        'GET productspdf' => 'auth',
        'POST productspdf' => 'auth',
        'GET productspdf/create' => 'auth',
        'DELETE productspdf/{productspdf}' => 'auth',
        'GET productspdf/{productspdf}' => 'auth',
        'PATCH productspdf/{productspdf}' => 'auth',
        'PUT productspdf/{productspdf}' => 'auth',
        'GET productspdf/{productspdf}/edit' => 'auth',
        'POST purchase-notification-setting' => 'settings:store',
        'POST recent-orders-settings' => 'settings:store',
        'POST recently-view-setting' => 'settings:store',
        /*
         * P1-SEC-89 (b) -- THE POINT TRANSACTION ITSELF.
         *
         * `show($id)` is `RewardClubPoint::find($id)` then `explode(',', $row->product_id)`, with
         * no check of any kind, so another tenant's transaction rendered its product ids; and a
         * null row made `->product_id` a 500 rather than a refusal.
         *
         * `reward_club_points` carries `store_id`, so the existing rule reaches it. Here the
         * parameter IS the reward row's id, which is why this rule names a different table from
         * the history route above.
         *
         * THE OTHER VERBS ARE INERT AND ARE CLASSIFIED ANYWAY: `destroy()` and `update()` have
         * empty bodies (`//`) and `edit()` returns a static view and ignores its id. Listed for
         * the reason P1-SEC-84 listed `contacts/update` and P1-SEC-87 the three dead resource
         * verbs -- a rule must not depend on a method body staying empty.
         */
        'DELETE rewardclubpoint/{rewardclubpoint}' => 'store-row:reward_club_points:rewardclubpoint',
        'GET rewardclubpoint/{rewardclubpoint}' => 'store-row:reward_club_points:rewardclubpoint',
        'PATCH rewardclubpoint/{rewardclubpoint}' => 'store-row:reward_club_points:rewardclubpoint',
        'PUT rewardclubpoint/{rewardclubpoint}' => 'store-row:reward_club_points:rewardclubpoint',
        'GET rewardclubpoint/{rewardclubpoint}/edit' => 'store-row:reward_club_points:rewardclubpoint',
        'GET request-amount/{id}/{status}' => 'store-row:transaction_orders:id',
        'GET search-products-barcode' => 'auth',
        'POST skip-cart-settings' => 'settings:store',
        'POST slack-settings' => 'settings:global',
        'POST sms-settings' => 'settings:global',
        'POST spam-prevent' => 'auth',
        'POST store-donation' => 'settings:store',
        'POST store-quick-checkout' => 'settings:store',
        'GET subscribe' => 'auth',
        'GET subscribe/create' => 'auth',
        'DELETE subscribe/{subscribe}' => 'auth',
        'GET subscribe/{subscribe}' => 'auth',
        'PATCH subscribe/{subscribe}' => 'auth',
        'PUT subscribe/{subscribe}' => 'auth',
        'GET subscribe/{subscribe}/edit' => 'auth',
        'POST tawk-to-setting' => 'settings:global',
        'POST tawktomessenger-form' => 'auth',
        'POST theme-hours-settings' => 'auth',
        'POST ticket-conversion-store/{ticketId}' => 'owner:helpdesk-ticket-row',
        'POST ticket-note-store/{ticketId}' => 'owner:helpdesk-ticket-row',
        'GET ticket-reply/{ticketId}' => 'owner:helpdesk-ticket-link',
        'POST twitter-settings' => 'settings:global',
        'POST update-auction-status' => 'auth',
        'POST update-ticket/{ticketId}' => 'owner:helpdesk-ticket-link',
        'GET wholesaleproduct' => 'auth',
        'POST wholesaleproduct' => 'auth',
        'GET wholesaleproduct/create' => 'auth',
        'DELETE wholesaleproduct/{wholesaleproduct}' => 'auth',
        'GET wholesaleproduct/{wholesaleproduct}' => 'auth',
        'PATCH wholesaleproduct/{wholesaleproduct}' => 'auth',
        'PUT wholesaleproduct/{wholesaleproduct}' => 'auth',
        'GET wholesaleproduct/{wholesaleproduct}/edit' => 'auth',
        'GET wizzchat' => 'auth',
        'POST wizzchat' => 'auth',
        'POST wizzchat-setting' => 'settings:global',
        'GET wizzchat/create' => 'auth',
        'DELETE wizzchat/{wizzchat}' => 'auth',
        'GET wizzchat/{wizzchat}' => 'auth',
        'PATCH wizzchat/{wizzchat}' => 'auth',
        'PUT wizzchat/{wizzchat}' => 'auth',
        'GET wizzchat/{wizzchat}/edit' => 'auth',
    ];

    /** @var array<string,string> `METHOD uri` => category */
    public const ANONYMOUS_BY_DESIGN = [
        'GET 2fa-verify' => 'storefront',
        'GET api/lynomia/commerce/v1/carts/abandoned' => 'own-credential:LynomiaChat',
        'POST api/lynomia/commerce/v1/customers/lookup' => 'own-credential:LynomiaChat',
        'GET api/lynomia/commerce/v1/customers/{customer}/orders' => 'own-credential:LynomiaChat',
        'GET api/lynomia/commerce/v1/orders' => 'own-credential:LynomiaChat',
        'GET api/lynomia/commerce/v1/orders/{order}' => 'own-credential:LynomiaChat',
        'GET api/lynomia/commerce/v1/store' => 'own-credential:LynomiaChat',
        'DELETE appoinments/store/{slug}' => 'storefront',
        'GET appoinments/store/{slug}' => 'storefront',
        'OPTIONS appoinments/store/{slug}' => 'storefront',
        'PATCH appoinments/store/{slug}' => 'storefront',
        'POST appoinments/store/{slug}' => 'storefront',
        'PUT appoinments/store/{slug}' => 'storefront',
        'GET cartquantitycontrol' => 'inert:unresolvable-middleware',
        'POST cartquantitycontrol' => 'inert:unresolvable-middleware',
        'GET cartquantitycontrol/create' => 'inert:unresolvable-middleware',
        'DELETE cartquantitycontrol/{cartquantitycontrol}' => 'inert:unresolvable-middleware',
        'GET cartquantitycontrol/{cartquantitycontrol}' => 'inert:unresolvable-middleware',
        'PATCH cartquantitycontrol/{cartquantitycontrol}' => 'inert:unresolvable-middleware',
        'PUT cartquantitycontrol/{cartquantitycontrol}' => 'inert:unresolvable-middleware',
        'GET cartquantitycontrol/{cartquantitycontrol}/edit' => 'inert:unresolvable-middleware',
        'GET auction-order-receipt/{id}' => 'guarded:OrderDocumentAccessGuard',
        'GET auction/shippinglabel/pdf/{id}' => 'guarded:OrderDocumentAccessGuard',
        'GET campaigns/campaigns-DPO/create' => 'payment-callback',
        'DELETE campaigns/campaigns-Powertranz' => 'payment-callback',
        'GET campaigns/campaigns-Powertranz' => 'payment-callback',
        'OPTIONS campaigns/campaigns-Powertranz' => 'payment-callback',
        'PATCH campaigns/campaigns-Powertranz' => 'payment-callback',
        'POST campaigns/campaigns-Powertranz' => 'payment-callback',
        'PUT campaigns/campaigns-Powertranz' => 'payment-callback',
        'GET campaigns/campaigns-braintree-payment' => 'payment-callback',
        'DELETE campaigns/campaigns-card-payment' => 'guarded:CampaignIntentOwnershipGuard',
        'GET campaigns/campaigns-card-payment' => 'guarded:CampaignIntentOwnershipGuard',
        'OPTIONS campaigns/campaigns-card-payment' => 'guarded:CampaignIntentOwnershipGuard',
        'PATCH campaigns/campaigns-card-payment' => 'guarded:CampaignIntentOwnershipGuard',
        'POST campaigns/campaigns-card-payment' => 'guarded:CampaignIntentOwnershipGuard',
        'PUT campaigns/campaigns-card-payment' => 'guarded:CampaignIntentOwnershipGuard',
        'DELETE campaigns/campaigns-get-DPO-status' => 'payment-callback',
        'GET campaigns/campaigns-get-DPO-status' => 'payment-callback',
        'OPTIONS campaigns/campaigns-get-DPO-status' => 'payment-callback',
        'PATCH campaigns/campaigns-get-DPO-status' => 'payment-callback',
        'POST campaigns/campaigns-get-DPO-status' => 'payment-callback',
        'PUT campaigns/campaigns-get-DPO-status' => 'payment-callback',
        'GET campaigns/campaigns-pay-with/DPO' => 'payment-callback',
        'GET campaigns/campaigns-pay-with/Powertranz' => 'payment-callback',
        'GET campaigns/campaigns-payu-payment/{pay}' => 'payment-callback',
        'GET campaigns/campaigns-sort' => 'storefront',
        'GET campaigns/campaigns/contact-view/{id}' => 'storefront',
        'GET campaigns/campaigns/share/{slug}' => 'storefront',
        'GET cards/{slug}' => 'storefront',
        'GET download/{slug}' => 'storefront',
        'DELETE duologin' => 'guarded:Duo2FALoginGuard',
        'GET duologin' => 'guarded:Duo2FALoginGuard',
        'OPTIONS duologin' => 'guarded:Duo2FALoginGuard',
        'PATCH duologin' => 'guarded:Duo2FALoginGuard',
        'POST duologin' => 'guarded:Duo2FALoginGuard',
        'PUT duologin' => 'guarded:Duo2FALoginGuard',
        'POST getstore' => 'storefront',
        'POST join_us/store' => 'storefront',
        'GET landing-pages/{slug}' => 'storefront',
        'GET lottery-order-receipt/{id}' => 'guarded:OrderDocumentAccessGuard',
        'GET lottery-order-view/{id}' => 'guarded:OrderDocumentAccessGuard',
        'GET lottery/shippinglabel/pdf/{id}' => 'guarded:OrderDocumentAccessGuard',
        'POST lynomia/events/{store}' => 'webhook',
        'POST lynomia/platform-events' => 'webhook',
        'POST manage-customer-data/{slug?}' => 'storefront',
        'POST manage-winner-customer/{slug?}' => 'storefront',
        'DELETE marketing/contacts/store/{slug}' => 'storefront',
        'GET marketing/contacts/store/{slug}' => 'storefront',
        'OPTIONS marketing/contacts/store/{slug}' => 'storefront',
        'PATCH marketing/contacts/store/{slug}' => 'storefront',
        'POST marketing/contacts/store/{slug}' => 'storefront',
        'PUT marketing/contacts/store/{slug}' => 'storefront',
        'POST order-review-store/{slug}/{order?}' => 'storefront',
        'GET order-review/{slug}/{order?}' => 'storefront',
        'GET printlabel/{id?}/{template?}/{color?}' => 'guarded:OrderDocumentAccessGuard',
        'POST productcatelog' => 'storefront',
        'GET request-amount-cancel/{id}' => 'storefront',
        'GET request-amount-sent/{id}' => 'storefront',
        'POST request-amount-store/{id}' => 'storefront',
        'GET shippinglabel/{id?}/{template?}/{color?}' => 'guarded:OrderDocumentAccessGuard',
        'POST skip-empty-cart' => 'storefront',
        'GET spam-fetch-prevent' => 'guarded:ModuleStorefrontInputGuard',
        'GET spintowinreward-create/{slug?}' => 'storefront',
        'POST spintowinreward-store/{slug?}' => 'storefront',
        'POST subscribe' => 'storefront',
        'GET {slug}/auth/bitbucket' => 'oauth',
        'GET {slug}/auth/bitbucket/callback' => 'oauth',
        'GET {slug}/auth/facebook' => 'oauth',
        'GET {slug}/auth/facebook/callback' => 'oauth',
        'GET {slug}/auth/github' => 'oauth',
        'GET {slug}/auth/github/callback' => 'oauth',
        'GET {slug}/auth/google' => 'oauth',
        'GET {slug}/auth/google/call-back' => 'oauth',
        'GET {slug}/auth/linkedin' => 'oauth',
        'GET {slug}/auth/linkedin/callback' => 'oauth',
        'GET {slug}/auth/outlook' => 'oauth',
        'GET {slug}/auth/outlook/call-back' => 'oauth',
        'GET {slug}/auth/slack' => 'oauth',
        'GET {slug}/auth/slack/call-back' => 'oauth',
        'GET {slug}/auth/twitter' => 'oauth',
        'GET {slug}/auth/twitter/callback' => 'oauth',
        'GET {slug}/coupencode/get' => 'storefront',
        'POST {slug}/coupencode/request/store' => 'storefront',
        'GET {slug}/product-catelog-list' => 'storefront',
        'GET {slug}/product-compare-list' => 'storefront',
        'GET {slug}/wholesale_price/{id}' => 'storefront',
        'GET {storeSlug?}/customerpayment/{id}' => 'owner-exemption:S12-payment-by-link-and-emailed-order-link',
        'GET {storeSlug?}/donation-success' => 'storefront',
        'DELETE {storeSlug?}/donation-telegram' => 'storefront',
        'GET {storeSlug?}/donation-telegram' => 'storefront',
        'OPTIONS {storeSlug?}/donation-telegram' => 'storefront',
        'PATCH {storeSlug?}/donation-telegram' => 'storefront',
        'POST {storeSlug?}/donation-telegram' => 'storefront',
        'PUT {storeSlug?}/donation-telegram' => 'storefront',
        'DELETE {storeSlug?}/donation-whatsapp' => 'storefront',
        'GET {storeSlug?}/donation-whatsapp' => 'storefront',
        'OPTIONS {storeSlug?}/donation-whatsapp' => 'storefront',
        'PATCH {storeSlug?}/donation-whatsapp' => 'storefront',
        'POST {storeSlug?}/donation-whatsapp' => 'storefront',
        'PUT {storeSlug?}/donation-whatsapp' => 'storefront',
        'GET {storeSlug?}/ticket-success' => 'storefront',
        'DELETE {storeSlug?}/ticket-telegram' => 'storefront',
        'GET {storeSlug?}/ticket-telegram' => 'payment-callback',
        'OPTIONS {storeSlug?}/ticket-telegram' => 'storefront',
        'PATCH {storeSlug?}/ticket-telegram' => 'storefront',
        'POST {storeSlug?}/ticket-telegram' => 'storefront',
        'PUT {storeSlug?}/ticket-telegram' => 'storefront',
        'DELETE {storeSlug?}/ticket-whatsapp' => 'storefront',
        'GET {storeSlug?}/ticket-whatsapp' => 'payment-callback',
        'OPTIONS {storeSlug?}/ticket-whatsapp' => 'storefront',
        'PATCH {storeSlug?}/ticket-whatsapp' => 'storefront',
        'POST {storeSlug?}/ticket-whatsapp' => 'storefront',
        'PUT {storeSlug?}/ticket-whatsapp' => 'storefront',
        'GET {storeSlug}/auction-order-list' => 'storefront',
        'GET {storeSlug}/auction-order/{id}' => 'owner-exemption:S12-payment-by-link-and-emailed-order-link',
        'GET {storeSlug}/auction-product/{product_slug}' => 'storefront',
        'POST {storeSlug}/boost-sales-cart' => 'storefront',
        'POST {storeSlug}/boost-sales-price' => 'storefront',
        'POST {storeSlug}/checkout-cart' => 'storefront',
        'GET {storeSlug}/coupons' => 'storefront',
        'GET {storeSlug}/customer-donation-create' => 'storefront',
        'POST {storeSlug}/customer-donation-store' => 'storefront',
        'POST {storeSlug}/donation-get-massage' => 'storefront',
        'POST {storeSlug}/frequently_product_cart' => 'storefront',
        'POST {storeSlug}/frequently_product_price' => 'storefront',
        'POST {storeSlug}/get-donation-amount' => 'storefront',
        'DELETE {storeSlug}/get-donation-payment-aamarpay' => 'payment-callback',
        'GET {storeSlug}/get-donation-payment-aamarpay' => 'payment-callback',
        'OPTIONS {storeSlug}/get-donation-payment-aamarpay' => 'payment-callback',
        'PATCH {storeSlug}/get-donation-payment-aamarpay' => 'payment-callback',
        'POST {storeSlug}/get-donation-payment-aamarpay' => 'payment-callback',
        'PUT {storeSlug}/get-donation-payment-aamarpay' => 'payment-callback',
        'DELETE {storeSlug}/get-donation-payment-iyzipay' => 'payment-callback',
        'GET {storeSlug}/get-donation-payment-iyzipay' => 'payment-callback',
        'OPTIONS {storeSlug}/get-donation-payment-iyzipay' => 'payment-callback',
        'PATCH {storeSlug}/get-donation-payment-iyzipay' => 'payment-callback',
        'POST {storeSlug}/get-donation-payment-iyzipay' => 'payment-callback',
        'PUT {storeSlug}/get-donation-payment-iyzipay' => 'payment-callback',
        'DELETE {storeSlug}/get-donation-payment-midtrans' => 'payment-callback',
        'GET {storeSlug}/get-donation-payment-midtrans' => 'payment-callback',
        'OPTIONS {storeSlug}/get-donation-payment-midtrans' => 'payment-callback',
        'PATCH {storeSlug}/get-donation-payment-midtrans' => 'payment-callback',
        'POST {storeSlug}/get-donation-payment-midtrans' => 'payment-callback',
        'PUT {storeSlug}/get-donation-payment-midtrans' => 'payment-callback',
        'DELETE {storeSlug}/get-donation-payment-paytm' => 'payment-callback',
        'GET {storeSlug}/get-donation-payment-paytm' => 'payment-callback',
        'OPTIONS {storeSlug}/get-donation-payment-paytm' => 'payment-callback',
        'PATCH {storeSlug}/get-donation-payment-paytm' => 'payment-callback',
        'POST {storeSlug}/get-donation-payment-paytm' => 'payment-callback',
        'PUT {storeSlug}/get-donation-payment-paytm' => 'payment-callback',
        'DELETE {storeSlug}/get-ticket-payment-aamarpay' => 'payment-callback',
        'GET {storeSlug}/get-ticket-payment-aamarpay' => 'payment-callback',
        'OPTIONS {storeSlug}/get-ticket-payment-aamarpay' => 'payment-callback',
        'PATCH {storeSlug}/get-ticket-payment-aamarpay' => 'payment-callback',
        'POST {storeSlug}/get-ticket-payment-aamarpay' => 'payment-callback',
        'PUT {storeSlug}/get-ticket-payment-aamarpay' => 'payment-callback',
        'DELETE {storeSlug}/get-ticket-payment-midtrans' => 'payment-callback',
        'GET {storeSlug}/get-ticket-payment-midtrans' => 'payment-callback',
        'OPTIONS {storeSlug}/get-ticket-payment-midtrans' => 'payment-callback',
        'PATCH {storeSlug}/get-ticket-payment-midtrans' => 'payment-callback',
        'POST {storeSlug}/get-ticket-payment-midtrans' => 'payment-callback',
        'PUT {storeSlug}/get-ticket-payment-midtrans' => 'payment-callback',
        'DELETE {storeSlug}/get-ticket-payment-paytm' => 'payment-callback',
        'GET {storeSlug}/get-ticket-payment-paytm' => 'payment-callback',
        'OPTIONS {storeSlug}/get-ticket-payment-paytm' => 'payment-callback',
        'PATCH {storeSlug}/get-ticket-payment-paytm' => 'payment-callback',
        'POST {storeSlug}/get-ticket-payment-paytm' => 'payment-callback',
        'PUT {storeSlug}/get-ticket-payment-paytm' => 'payment-callback',
        'POST {storeSlug}/lottery-form-data' => 'storefront',
        'GET {storeSlug}/lottery-form/{product_slug}' => 'storefront',
        'GET {storeSlug}/lottery-product/{product_slug}' => 'storefront',
        'POST {storeSlug}/make-payment' => 'storefront',
        'POST {storeSlug}/manage-deposit' => 'storefront',
        'GET {storeSlug}/market' => 'storefront',
        'GET {storeSlug}/on-page-checkout' => 'storefront',
        'GET {storeSlug}/place-bid/{product_slug}' => 'storefront',
        'GET {storeSlug}/preordersetting/out-of-stock-products' => 'storefront',
        'POST {storeSlug}/product-bid' => 'storefront',
        'POST {storeSlug}/product-enquiry' => 'storefront',
        'GET {storeSlug}/product-quick-view/{product_slug}' => 'storefront',
        'POST {storeSlug}/product-review' => 'guarded:ModuleStorefrontInputGuard',
        'GET {storeSlug}/product-review/{product_slug}' => 'storefront',
        'GET {storeSlug}/product_compare/{product_slug}' => 'storefront',
        'DELETE {storeSlug}/product_compare_remove/{id}' => 'storefront',
        'GET {storeSlug}/product_compare_remove/{id}' => 'storefront',
        'OPTIONS {storeSlug}/product_compare_remove/{id}' => 'storefront',
        'PATCH {storeSlug}/product_compare_remove/{id}' => 'storefront',
        'POST {storeSlug}/product_compare_remove/{id}' => 'storefront',
        'PUT {storeSlug}/product_compare_remove/{id}' => 'storefront',
        'GET {storeSlug}/product_pdf/{product_slug}' => 'storefront',
        'GET {storeSlug}/product_review/{id}' => 'storefront',
        'POST {storeSlug}/refund-lottery-form-data' => 'storefront',
        'GET {storeSlug}/refund-lottery-form/{product_slug}' => 'storefront',
        'POST {storeSlug}/spamprevent' => 'guarded:ModuleStorefrontInputGuard',
        'GET {storeSlug}/store-donation-payment' => 'payment-callback',
        'DELETE {storeSlug}/store-donation-payment-authorized' => 'payment-callback',
        'GET {storeSlug}/store-donation-payment-authorized' => 'payment-callback',
        'OPTIONS {storeSlug}/store-donation-payment-authorized' => 'payment-callback',
        'PATCH {storeSlug}/store-donation-payment-authorized' => 'payment-callback',
        'POST {storeSlug}/store-donation-payment-authorized' => 'payment-callback',
        'PUT {storeSlug}/store-donation-payment-authorized' => 'payment-callback',
        'DELETE {storeSlug}/store-donation-payment-khalti' => 'payment-callback',
        'GET {storeSlug}/store-donation-payment-khalti' => 'payment-callback',
        'OPTIONS {storeSlug}/store-donation-payment-khalti' => 'payment-callback',
        'PATCH {storeSlug}/store-donation-payment-khalti' => 'payment-callback',
        'POST {storeSlug}/store-donation-payment-khalti' => 'payment-callback',
        'PUT {storeSlug}/store-donation-payment-khalti' => 'payment-callback',
        'GET {storeSlug}/store-lottery-payment' => 'payment-callback',
        'DELETE {storeSlug}/store-ticket-payment-authorized' => 'payment-callback',
        'GET {storeSlug}/store-ticket-payment-authorized' => 'payment-callback',
        'OPTIONS {storeSlug}/store-ticket-payment-authorized' => 'payment-callback',
        'PATCH {storeSlug}/store-ticket-payment-authorized' => 'payment-callback',
        'POST {storeSlug}/store-ticket-payment-authorized' => 'payment-callback',
        'PUT {storeSlug}/store-ticket-payment-authorized' => 'payment-callback',
        'DELETE {storeSlug}/store-ticket-payment-khalti' => 'payment-callback',
        'GET {storeSlug}/store-ticket-payment-khalti' => 'payment-callback',
        'OPTIONS {storeSlug}/store-ticket-payment-khalti' => 'payment-callback',
        'PATCH {storeSlug}/store-ticket-payment-khalti' => 'payment-callback',
        'POST {storeSlug}/store-ticket-payment-khalti' => 'payment-callback',
        'PUT {storeSlug}/store-ticket-payment-khalti' => 'payment-callback',
        'POST {storeSlug}/ticket-get-massage' => 'storefront',
        'GET {storeslug}/csvExport/{id}' => 'storefront',
    ];

    /** @var array<string,array<string,string>>|null uri => [METHOD => rule], built once per process */
    private static ?array $byUri = null;

    public static function administrativeRule(string $method, string $uri): ?string
    {
        if (self::$byUri === null) {
            self::$byUri = [];

            foreach (self::ADMINISTRATIVE as $key => $rule) {
                [$verb, $path] = explode(' ', $key, 2);
                self::$byUri[$path][$verb] = $rule;
            }
        }

        $method = strtoupper($method);

        return self::$byUri[$uri][$method === 'HEAD' ? 'GET' : $method] ?? null;
    }
}
