<?php

namespace App\Http\Middleware;

use App\Services\ModulePaymentLedger;
use App\Support\ModuleRouteManifest;
use App\Support\Modules\ModuleAvailability;
use App\Support\Modules\ModulePaymentFlows;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A SWITCHED-OFF APPLICATION'S AUTHENTICATED ROUTES ARE REFUSED, AND SO IS ANY NEW PAYMENT THROUGH IT.
 *
 * Phase 06 item 2(B). `ModuleAvailability` has the measurement: disabling an application removed
 * nothing, because `PackageServiceProvider:47` loads every module regardless of `is_enable`. Census
 * through the production loader (`tests/Support/Security/bin/route-census.php`, 70 modules, 0 load
 * failures): **1,464 of the 3,058 routes in the table are owned by a module**, across 68 modules, and
 * every one of them stayed mounted for an application the operator had switched off.
 *
 * HOST-SIDE, BECAUSE `packages/workdo/` IS VENDORED and must not be edited. That is the same reason
 * `ModuleAdministrativeRouteGuard` exists, and this guard deliberately copies its shape: one
 * first-party middleware, the module's own code untouched.
 *
 * WHY A REQUEST GUARD AND NOT `where('is_enable', 1)` AT THE LOADER
 * =================================================================
 *
 * Filtering the loader looks like the obvious one-line fix and is the wrong one. `loadAddons()`
 * registers more than routes: the module's migrations, console commands and event listeners come
 * with it. Filtering there would mean a switched-off application could not be migrated, could not be
 * re-enabled cleanly, and would silently stop whatever scheduled work it owns. That is a far larger
 * behaviour change than the requirement asks for, and it fails by BREAKING rather than by refusing.
 *
 * WHAT THIS COVERS, AND WHAT IT DELIBERATELY DOES NOT
 * ===================================================
 *
 * Measured against the 1,464 module routes, by what a route IS rather than by how it is labelled:
 *
 *   AUTHENTICATED (1,012: its own middleware, or a `ModuleRouteManifest::ADMINISTRATIVE` rule)
 *       REFUSED. Every screen and every writer a signed-in principal reaches.
 *   A PAYMENT, by its controller action (`ModulePaymentFlows`; Donation, LotteryPrizes, Campaigns)
 *       A route that STARTS one is REFUSED. A route that FINISHES one is honoured ONCE, for an
 *       initiation the host recorded while the application was enabled, and otherwise refused and
 *       written down. That is the owner's rule -- *"keep a safe, authenticated and idempotent
 *       reconciliation mechanism for previously initiated payments; do not use it to permit new
 *       transactions through disabled applications"* -- and `paymentRouteOfADisabledModule()` has
 *       what it does and does not establish. It reaches all 87 of the manifest's `payment-callback`
 *       keys, and also 24 `storefront` and 6 `guarded` keys that are the same handlers under
 *       another label.
 *   WEBHOOK (2), OWN-CREDENTIAL (6), OWNER-EXEMPTION (2), GUARDED (the other 16), INERT (8)
 *       PASS. Authenticated by something of their own, already exempted by the owner, owned by
 *       another host guard, or unreachable.
 *   STOREFRONT (the other 76) and OAUTH (16)
 *       PASS, AND THIS IS THE PART THAT IS NOT ENGINEERING'S TO SETTLE. Whether switching an
 *       application off should take its shopper-facing pages down, or break an OAuth return that
 *       is already in flight, is the owner's decision and is still open.
 *
 * THE BOUNDARY ON PAYMENTS NEEDS A RECORD THE VENDORED MODULES DO NOT KEEP, so the host keeps it:
 * `module_payment_initiations`, a development-and-test-only migration like its two predecessors.
 * WHERE THAT TABLE IS ABSENT -- WHICH INCLUDES PRODUCTION UNTIL THE OWNER PROMOTES IT -- A PAYMENT
 * RETURN PASSES THROUGH EXACTLY AS IT DID BEFORE, because without the record a return cannot be
 * told from a new transaction and refusing it would lose a payment over a missing migration.
 * Starting a payment through a disabled application is refused either way.
 *
 * REFUSAL SHAPE, COPIED FROM `ModuleAdministrativeRouteGuard::deny()` (`:365-375`) RATHER THAN
 * INVENTED: JSON 403 when the caller expects JSON, otherwise a redirect carrying an error. **NEVER a
 * 401** -- CLAUDE.md records that the shipped Flutter admin app forces a logout on any 401, so a new
 * 401 on a path a client calls is a user-visible regression.
 *
 * REGISTERED IN BOTH THE `web` AND `api` GROUPS, because 18 of the 1,464 module routes sit outside
 * `web` (LynomiaChat's 8 commerce endpoints, CartQuantityControl's 8, and one each from LandingPage
 * and ProductsPDF). A web-only registration would have missed them, and that was measured rather
 * than assumed.
 */
class DisabledModuleRouteGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return $next($request);
        }

        $module = self::moduleOf($route);

        /*
         * A host route. 1,594 of the 3,058 routes are first-party and this is the common case, so it
         * costs nothing before any database read -- including, importantly, the module enable and
         * remove screens themselves, which live in the first-party `ModuleController` and must stay
         * reachable for an application that is switched off.
         */
        if ($module === null) {
            return $next($request);
        }

        /*
         * IS THIS ROUTE PART OF A PAYMENT? Asked BEFORE "is it authenticated", because for a payment
         * the answer decides everything and authentication decides nothing: a return from a gateway
         * is anonymous by nature, and the one Campaigns step that IS behind a login is still the
         * start of a transaction. See `ModulePaymentFlows` for why this is keyed on the action
         * rather than on the manifest's `payment-callback` label.
         */
        $role = ModulePaymentFlows::roleOf($route);

        if (! ModuleAvailability::isDisabled($module)) {
            /* ENABLED: nothing is ever refused here. The only thing done is to keep the record a
               later switch-off will need -- that a payment was started, and that it came back. */
            return $role === null ? $next($request) : $this->observe($request, $next, $route, $module, $role);
        }

        if ($role !== null) {
            return $this->paymentRouteOfADisabledModule($request, $next, $route, $module, $role);
        }

        if (! self::requiresAuthentication($route, $request->method())) {
            /*
             * WHAT STILL PASSES FOR A SWITCHED-OFF APPLICATION, by the manifest's own category:
             *
             *   storefront (the 76 keys not on a payment path) and oauth (16) -- THE OWNER'S, and
             *     still open: whether switching an application off should take its shopper-facing
             *     pages down, or break an OAuth return that is already in flight.
             *   webhook (2) and own-credential (6) -- LynomiaChat. Authenticated by an HMAC or a
             *     credential of their own, idempotent on a delivery id, and able to START nothing:
             *     they record the outcome of things already sent. Continuity, by the same rule as a
             *     payment that was already initiated.
             *   owner-exemption (2) -- an explicit owner exemption already.
             *   guarded (22, less the Campaigns writer handled above) and inert (8) -- another host
             *     guard owns the first, and nothing can reach the second.
             */
            return $next($request);
        }

        return $this->deny($request);
    }

    /**
     * A PAYMENT ROUTE OF A SWITCHED-OFF APPLICATION. The owner's rule, in the owner's words:
     *
     * > *"Keep a safe, authenticated and idempotent reconciliation mechanism for previously
     * > initiated payments; do not use it to permit new transactions through disabled
     * > applications."*
     *
     *   START / STEP   a new transaction, or the next step of starting one. REFUSED. Nothing has
     *                  been charged when either runs, so the refusal costs the payer nothing.
     *   RELAY          on the way back. Passes only for an initiation this host recorded.
     *   COMPLETE       the writer. Passes ONCE for an initiation this host recorded, and the
     *                  initiation is spent by one conditional UPDATE -- so a refresh, a replayed
     *                  URL or a second tab finds nothing left to spend.
     *
     * "AUTHENTICATED" has a precise meaning here and it is not a login: the request must come from
     * the SESSION that started the payment, holding the 128-bit reference the server minted for it
     * while the application was still enabled. That is stronger than anything these handlers ever
     * asked for -- Donation's builds its record from the request when the session is empty.
     *
     * WHAT IT DOES NOT ESTABLISH, said plainly: that a provider took money. None of these modules
     * verifies a capture (one gateway in thirty-five excepted) and nothing here pretends to. The
     * boundary answers the question the rule asks -- was this payment started before the
     * application was switched off -- and leaves what the vendored handler does with it exactly as
     * it was.
     *
     * AND A RETURN THAT CANNOT SHOW AN INITIATION IS REFUSED AND WRITTEN DOWN, never dropped: the
     * session may have expired on a payer whose money was taken, and `payments:reconcile` is where
     * a person finds that out.
     */
    private function paymentRouteOfADisabledModule(Request $request, Closure $next, Route $route, string $module, string $role): Response
    {
        if ($role === ModulePaymentFlows::START || $role === ModulePaymentFlows::STEP) {
            return $this->deny($request);
        }

        if (ModulePaymentFlows::completionIsEntitlementGated($module)) {
            /*
             * THIS MODULE CANNOT COMPLETE A PAYMENT WHILE IT IS SWITCHED OFF, WHATEVER THIS GUARD
             * DOES -- so honouring the return would be a promise this application cannot keep, and
             * letting the relay through would take money for a record that cannot be written.
             * `ModulePaymentFlows::COMPLETION_BEHIND_THE_MODULES_OWN_GATE` has the measurement:
             * Campaigns' writer is inside `PlanModuleCheck:Campaigns`, route middleware runs after
             * the group's, and the PowerTranz relay is the step that captures.
             *
             * THE INITIATION IS LEFT OPEN. Nothing was written, so there is nothing to spend, and if
             * the operator switches the application back on the payer can still complete. The
             * refusal is recorded either way, so the payment is visible to `payments:reconcile`
             * rather than disappearing into a redirect.
             */
            return $this->refuse(
                $request,
                $route,
                $module,
                "this module's own entitlement gate refuses its payment writer while the application "
                . 'is switched off, so the return could not have been honoured',
                $this->heldReference($request, $module) !== null
            );
        }

        if (! ModulePaymentLedger::available()) {
            /*
             * NO RECORD, SO NO WAY TO TELL A RETURN FROM A NEW TRANSACTION -- and the direction for
             * that is the one the route already had. Refusing here would turn an outstanding
             * migration into a lost payment, which is the exact harm the owner's rule exists to
             * prevent. `ModulePaymentLedger`'s header has the full argument.
             *
             * ONE THING IS STILL REQUIRED, AND IT NEEDS NO TABLE: the VENDORED flow key. Independent
             * review found that without it a single anonymous request to a disabled Donation
             * module's return URL still writes a `customer_donations` row, because that handler
             * builds its record from the request when the session is empty -- a brand new
             * transaction through a switched-off application, which is what the owner's sentence
             * forbids. The module puts that key in the session when the payment is initiated, and a
             * disabled module cannot initiate one, so requiring it admits an in-flight payer and
             * nobody else. `LotteryTicketWriteGuard` already makes this exact argument for its own
             * handler.
             */
            if (! $this->holdsTheModulesOwnFlowState($request, $module)) {
                /* NO APOSTROPHE IN THE MESSAGE, DELIBERATELY. `SecurityLogInventoryTest`'s census
                   reads the literal up to the first quote, so a possessive would store this row in
                   the observability inventory as a truncated half-sentence. */
                Log::warning('Disabled-module payment return refused: no initiation record is installed and the session holds none of the vendored payment state.', [
                    'module' => $module,
                    'route' => self::key($request, $route),
                ]);

                return $this->deny($request);
            }

            Log::warning('Disabled-module payment return passed through on the vendored session state: the initiation record is not installed.', [
                'module' => $module,
                'route' => self::key($request, $route),
            ]);

            return $next($request);
        }

        $reference = $this->heldReference($request, $module);

        /*
         * THE COMPLETION MUST HAPPEN IN THE STORE THE PAYMENT STARTED IN. Independent review's
         * finding: the reference says WHOSE payment this is and said nothing about WHERE, so a payer
         * who began a donation at store A could, after the switch-off, present the same session at
         * store B and the vendored handler would write the row into store B. The row carries the
         * store the server resolved at initiation, so this compares the request against the server's
         * own record rather than against another value the caller chose.
         */
        [$exists, $startedIn] = ModulePaymentLedger::initiationStore($module, $reference);
        $arrivingAt = $this->storeIdOf($request);

        if ($exists && $startedIn !== null && $arrivingAt !== null && $startedIn !== $arrivingAt) {
            return $this->refuse(
                $request,
                $route,
                $module,
                'the payment initiation this session holds was started in a different store',
                true
            );
        }

        $proven = $role === ModulePaymentFlows::RELAY
            ? ModulePaymentLedger::hasOpenInitiation($module, $reference)
            : ModulePaymentLedger::completeInitiation($module, $reference);

        if (! $proven) {
            return $this->refuse(
                $request,
                $route,
                $module,
                $reference === null
                    ? 'no payment initiation is held by this session'
                    : 'the payment initiation this session holds is not open (already completed, superseded or unknown)',
                $reference !== null
            );
        }

        $response = $next($request);

        /*
         * THE CLAIM IS PUT BACK IF SOMETHING FURTHER DOWN REFUSED THE REQUEST.
         *
         * The claim has to be taken BEFORE the handler runs -- it is what makes two concurrent
         * returns produce one write. But a request refused after it was claimed left a row saying
         * `completed` for a payment nobody recorded, which is the lie independent review found on
         * the Campaigns path and which is possible on the other two whenever the vendored handler
         * rejects a return without writing. Reopening is safe for the same reason it is needed:
         * nothing was written.
         */
        if ($role === ModulePaymentFlows::COMPLETE && self::wasRefusedDownstream($request, $response)) {
            if (ModulePaymentLedger::reopenInitiation($module, $reference)) {
                ModulePaymentLedger::recordRefusedCompletion(
                    $module,
                    $arrivingAt,
                    self::key($request, $route),
                    self::fingerprint($request),
                    'the payment return was refused after this boundary allowed it, so nothing was recorded'
                );

                Log::warning('Disabled-module payment return was allowed and then refused downstream; the initiation was put back.', [
                    'module' => $module,
                    'route' => self::key($request, $route),
                    'status' => $response->getStatusCode(),
                ]);
            }
        }

        return $response;
    }

    /**
     * REFUSE A RETURN AND WRITE IT DOWN. Never dropped: the session may have expired on a payer
     * whose money was taken, and `payments:reconcile` is where a person finds that out.
     */
    private function refuse(Request $request, Route $route, string $module, string $reason, bool $heldAReference): Response
    {
        ModulePaymentLedger::recordRefusedCompletion(
            $module,
            $this->storeIdOf($request),
            self::key($request, $route),
            self::fingerprint($request),
            $reason
        );

        Log::warning('Disabled-module payment return refused.', [
            'module' => $module,
            'route' => self::key($request, $route),
            'reason' => $reason,
            'session_held_a_reference' => $heldAReference,
        ]);

        return $this->deny($request);
    }

    /**
     * DID SOMETHING AFTER THIS GUARD REFUSE THE REQUEST?
     *
     * Two shapes, because the stack uses both: an error status, and the redirect-with-a-flashed-
     * error that every refusal in this codebase's web paths answers with (`PlanModuleCheck`,
     * `CampaignIntentOwnershipGuard`, the vendored handlers' own validation).
     *
     * `_flash.new` RATHER THAN `has('error')`, and that distinction is a defect independent review
     * found in the first version: a flash written by the PREVIOUS request is still readable during
     * this one (it is aged when the session is saved), so `has('error')` also fired on a stale
     * message and reported a successful return as refused. `_flash.new` holds only what THIS
     * request flashed.
     */
    private static function wasRefusedDownstream(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }

        if (! $response->isRedirection() || ! $request->hasSession()) {
            return false;
        }

        return in_array('error', (array) $request->session()->get('_flash.new', []), true);
    }

    /**
     * Does the session hold the VENDORED module's own payment state -- the key its service writes
     * when a payment is initiated?
     *
     * Only consulted where this host's own record is absent. It is a weaker claim than the
     * reference (it is not single-use and not store-bound), and it is the strongest thing available
     * without the table: a disabled module cannot initiate a payment, so nothing can newly obtain
     * it. Read, never written -- the vendored key is the module's.
     */
    private function holdsTheModulesOwnFlowState(Request $request, string $module): bool
    {
        $key = ModulePaymentFlows::vendoredFlowKey($module);

        if ($key === null || ! $request->hasSession()) {
            return false;
        }

        /* The handlers' own test is `== null`, which is also true of `''` and of an empty array, so
           the guard has to ask the same question or it admits a value the handler then rejects --
           `LotteryTicketWriteGuard` records the same reasoning for the same key. */
        return $request->session()->get($key) != null;
    }

    /**
     * THE BOOKKEEPING FOR AN ENABLED APPLICATION. It refuses nothing and changes no response.
     *
     * START is recorded BEFORE the vendored handler runs, not after. One of these gateways ends the
     * request with a bare `exit;` from inside the vendored service, so code placed after `$next()`
     * would never run for it and that payment would have no record at all. Recording first costs a
     * row for a form the handler then rejects -- so when the handler DOES come back, and comes back
     * with its own flashed error, that row is marked abandoned.
     *
     * AND FOR THAT ONE GATEWAY THE ROW CANNOT BE REDEEMED, which independent review established and
     * which this docblock should not imply otherwise. `StartSession` saves the session AFTER the
     * response, so a service that calls `exit;` (aamarpay, in all three modules) never lets the
     * reference reach the payer's session -- the row stays `initiated` and their return will be
     * refused and recorded rather than honoured. Not a regression: the vendored flow loses its OWN
     * session state the same way, so that payment could not complete before this change either. It
     * is named here because "the record is written first so nothing is lost" is true of the row and
     * not of the session.
     *
     * COMPLETE marks the initiation spent when the payer returns. For an enabled application this
     * gates nothing; it keeps the record true, so that a return already honoured is not honoured a
     * second time the day the application is switched off.
     */
    private function observe(Request $request, Closure $next, Route $route, string $module, string $role): Response
    {
        if (! $request->hasSession() || ! ModulePaymentLedger::available()) {
            return $next($request);
        }

        $key = ModulePaymentLedger::sessionKey($module);

        if ($role === ModulePaymentFlows::START) {
            $field = ModulePaymentFlows::methodField($module);
            $method = $field !== null ? $request->input($field) : null;
            [$guard, $payerId] = $this->payerOf();

            $reference = ModulePaymentLedger::recordInitiation(
                $module,
                $this->storeIdOf($request),
                self::key($request, $route),
                $guard,
                $payerId,
                is_scalar($method) ? (string) $method : null,
                $this->heldReference($request, $module)
            );

            if ($reference === null) {
                return $next($request);
            }

            $request->session()->put($key, $reference);

            $response = $next($request);

            if (self::wasRefusedDownstream($request, $response)) {
                /* The three START handlers all reject a form the same way: `redirect()->back()
                   ->with('error', ...)`. Nothing left for a gateway, so nothing was initiated.
                   `wasRefusedDownstream()` tests the flash THIS request wrote, not one left over
                   from the last -- see its docblock. */
                ModulePaymentLedger::abandon($module, $reference);
                $request->session()->forget($key);
            }

            return $response;
        }

        if ($role === ModulePaymentFlows::COMPLETE) {
            ModulePaymentLedger::completeInitiation($module, $this->heldReference($request, $module));
        }

        return $next($request);
    }

    /** The reference this session holds for `$module`, or null. */
    private function heldReference(Request $request, string $module): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $reference = $request->session()->get(ModulePaymentLedger::sessionKey($module));

        /* WELL-FORMED OR ABSENT, never "whatever is in the session". The session is the payer's own
           and nothing else writes this key, but a claim must match on the value it asked for --
           see `ModulePaymentLedger::isWellFormedReference()` for the collation finding behind it. */
        return ModulePaymentLedger::isWellFormedReference($reference) ? $reference : null;
    }

    /**
     * WHO IS PAYING, for the record only. Each guard is named explicitly: `auth()->user()` reads the
     * default guard, which CLAUDE.md records can be any of three by the time a request gets here.
     *
     * @return array{0:?string,1:?int}
     */
    private function payerOf(): array
    {
        foreach (['customers', 'web'] as $guard) {
            $id = auth($guard)->id();

            if (is_numeric($id)) {
                return [$guard, (int) $id];
            }
        }

        return [null, null];
    }

    /**
     * The store a storefront route's slug names, for the record only -- never for a decision, and
     * never from `getCurrentStore()`, which answers for whoever is signed in to the panel.
     */
    private function storeIdOf(Request $request): ?int
    {
        $slug = self::slugOf($request);

        if ($slug === null) {
            return null;
        }

        try {
            $id = \App\Models\Store::query()->where('slug', $slug)->value('id');
        } catch (\Throwable $theStoreIsUnreadable) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }

    /** `METHOD uri`, the manifest's own key shape (HEAD folded into GET). */
    private static function key(Request $request, Route $route): string
    {
        $method = strtoupper($request->method());

        return ($method === 'HEAD' ? 'GET' : $method) . ' ' . $route->uri();
    }

    /**
     * A SHA-256 of the request's input, so a repeated refusal can be recognised without keeping
     * what was sent. One of these gateways puts a card number in a return URL; this table must
     * never hold one.
     */
    private static function fingerprint(Request $request): string
    {
        $input = $request->all();
        ksort($input);

        return hash('sha256', (string) json_encode($input, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The module that owns a route, from its action's namespace, or null for a host route.
     *
     * THE PATTERN IS THE PROJECT'S OWN: `route-census.php:75` derives the module with this exact
     * regex, so the control and the measurement agree on what "module-owned" means.
     *
     * THE CLAIM THIS DOCBLOCK USED TO MAKE WAS CIRCULAR, and independent review said so. It read
     * "the census MEASURED the mapping as exact in both directions ... no mismatches either way
     * across all 1,464 of them." But the census DEFINES module-ownership with the same regex, so it
     * cannot discover a route the regex misses -- such a route is simply counted as first-party.
     * What is actually measured, and does hold, is the forward direction: every `Workdo\<Module>\`
     * the regex captures is a real directory under `packages/workdo`, and every module's
     * `composer.json` PSR-4 key is `Workdo\<Directory>\` for all 70, `Signinwithgithub` included.
     *
     * TWO CLASSES ARE THEREFORE UNATTRIBUTABLE, and both fail OPEN -- they read as host routes:
     *
     *  1. A CLOSURE ACTION. `Route::getActionName()` returns the literal `'Closure'` when the action
     *     has no controller. Measured: 11 of the 137 module route files declare one. Most are the
     *     `auth:api` probe each module's `api.php` ships, and there is no `api` guard in this
     *     application (CLAUDE.md), so they throw for everybody -- latent rather than live.
     *  2. A MODULE-DECLARED ROUTE POINTING AT A FIRST-PARTY CONTROLLER. Measured: exactly two files
     *     import one, `AutomaticOrderPrinting/src/routes/web.php` (live) and
     *     `CheckoutAttachment/src/routes/web.php` (its route is commented out). The live one
     *     re-declares `shippinglabel/pdf/{id}` and takes the slot from the host's AUTHENTICATED
     *     declaration -- the case `bootstrap/app.php` already records -- so switching that module
     *     off neither un-mounts the route nor restores the host's version. Its ownership half is
     *     covered by `OrderDocumentAccessGuard`; its availability half is not.
     *
     * A REGEX CHANGE CANNOT FIX EITHER, which is why they are recorded rather than patched: the
     * declaring FILE is not available at request time, only the action. Closing them needs the
     * route-to-module mapping to come from the manifest rather than from the action name, which is
     * the same change the storefront widening needs and belongs with it.
     */
    public static function moduleOf(Route $route): ?string
    {
        return preg_match('/^Workdo\\\\([A-Za-z0-9_]+)\\\\/', (string) $route->getActionName(), $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * Is this route authenticated -- BY ANY OF THE THREE MECHANISMS THIS APPLICATION USES?
     *
     * CORRECTED AFTER INDEPENDENT REVIEW, AND THE CORRECTION WAS THE WHOLE POINT OF THE GUARD.
     * This read `gatherMiddleware()` alone, which is where `route-census.php` looks -- so the
     * control and the measurement agreed with each other and both were wrong, because this codebase
     * authenticates module routes in a way that puts nothing in the route's middleware array.
     *
     * MEASURED, on the real router, replaying PartialPayments' own two declarations in their own
     * order (`packages/workdo/PartialPayments/src/routes/web.php`, the resource at line 18 inside
     * `['auth','web','PlanModuleCheck:PartialPayments']` and THE SAME RESOURCE AGAIN at line 30
     * inside `['web']`):
     *
     *   GET partialpayments               middleware=[web]   gatherMiddleware says auth: NO
     *                                                        manifest rule:            'auth'
     *   POST payment-status-change/{id}   middleware=[web]   gatherMiddleware says auth: NO
     *                                                        manifest rule:            'auth'
     *
     * CLAUDE.md's rule is why: "Later route declarations REPLACE earlier ones for the same
     * method+URI, silently dropping the earlier middleware." So the second declaration wins and
     * `auth` is gone from the array. Those routes are authenticated -- by
     * `ModuleAdministrativeRouteGuard`, which is group middleware that resolves `auth('web')->user()`
     * for any `METHOD uri` carrying a `ModuleRouteManifest::ADMINISTRATIVE` rule (340 keys). That is
     * authentication; it is simply not route middleware, and the manifest exists precisely BECAUSE
     * these routes had none of their own.
     *
     * Without the second limb below, a merchant with PartialPayments switched off still reached the
     * whole `partialpayments` admin resource and `POST payment-status-change/{id}` -- which is
     * P1-SEC-04's own named effect. The guard's docblock claimed it closed "every screen and every
     * writer"; it closed the ones that say so in their own middleware.
     *
     * THE METHOD IS THE REQUEST'S, NOT `$route->methods()[0]`, for the reason
     * `ModuleAdministrativeRouteGuard` uses the request's: `Route::any` carries seven methods and
     * the manifest keys each separately, so the first one would answer for a verb the caller did not
     * send.
     *
     * `gatherMiddleware()` is the route's own list plus its groups', needs no router call, and DOES
     * include a controller's `__construct` middleware -- `Router::gatherRouteMiddleware()` memoises
     * `computedMiddleware` before the pipeline runs, so this is array work either way.
     */
    public static function requiresAuthentication(Route $route, ?string $method = null): bool
    {
        if (ModuleRouteManifest::administrativeRule($method ?? $route->methods()[0], $route->uri()) !== null) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth'
                || $middleware === 'custom.auth'
                || str_starts_with($middleware, 'auth:')
                || $middleware === \Illuminate\Auth\Middleware\Authenticate::class
                || str_starts_with($middleware, \Illuminate\Auth\Middleware\Authenticate::class . ':')
                || str_ends_with($middleware, '\\CustomAuth')
                || str_ends_with($middleware, '\\EnsureCustomerAuthenticated')) {
                return true;
            }
        }

        return false;
    }

    /**
     * THREE CALLERS, THREE ANSWERS, AND NEVER A 401.
     *
     * JSON for a caller that asked for JSON. For a browser, where the person can do something
     * about it: a signed-in panel user goes to their dashboard (`ModuleAdministrativeRouteGuard`'s
     * shape), and a SHOPPER goes back to the store they were in -- `LotteryTicketWriteGuard`
     * records why a bare 403 page is the wrong thing to hand somebody who was mid-purchase, and
     * the dashboard would be a login screen to them. With no store in the route, the site root.
     */
    private function deny(Request $request): Response
    {
        $message = __('This add-on is not available.');

        if ($request->expectsJson()) {
            return response()->json(['is_success' => false, 'msg' => $message, 'message' => $message], 403);
        }

        /*
         * THE ROUTE DECIDES, NOT THE SESSION. This read `auth('web')->check()`, and CLAUDE.md's rule
         * is that *"a `web` login is not a role"*: registration is open and the guards are separate
         * sessions, so a shopper who also holds a panel login was being sent to `/dashboard` from a
         * storefront page they were buying on. A route carrying a store slug is a storefront route,
         * whoever is signed in. Found by independent review.
         */
        $slug = self::slugOf($request);

        if ($slug !== null) {
            return redirect()->route('landing_page', $slug)->with('error', $message);
        }

        $route = $request->route();

        if ($route instanceof Route && ModulePaymentFlows::roleOf($route) !== null) {
            return redirect()->to('/')->with('error', $message);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }

    /**
     * The store slug a route carries, or null. `Route::parameter()` THROWS on a route that was
     * never bound to a request, and a guard that can throw is a guard that can 500.
     */
    private static function slugOf(Request $request): ?string
    {
        $route = $request->route();

        if (! $route instanceof Route || ! $route->hasParameters()) {
            return null;
        }

        foreach (['storeSlug', 'slug'] as $name) {
            $slug = $route->parameter($name);

            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }

        return null;
    }
}
