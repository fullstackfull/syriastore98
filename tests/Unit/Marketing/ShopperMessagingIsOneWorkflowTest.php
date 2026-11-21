<?php

namespace Tests\Unit\Marketing;

use Tests\TestCase;

/**
 * MKT-009 — ONE AUTHORITATIVE SHOPPER-MESSAGING WORKFLOW, AND A GUARD AGAINST A SECOND.
 * =================================================================================================
 *
 * The requirement is "Lynomia integration when ready, without duplication". The measured status was
 * `PARTIAL` for a precise reason: **there was no guard against the platform's own competing
 * shopper-messaging path.** Not a live duplication -- a missing control. This file is that control.
 *
 * WHAT IS AUTHORITATIVE TODAY, and it is first-party, not Lynomia. Four endpoints message a shopper
 * about an abandoned cart or wishlist, all through `AbandonedMessageDispatch` for the WhatsApp leg
 * and `Utility::sendEmailTemplate` for the e-mail leg, and since GAP-219 all four reserve through
 * `NudgeGate`. That is the workflow. `ROUTES` and `SENDER_FILES` below name it exactly, so "one
 * workflow" is a statement about named files rather than an assumption.
 *
 * WHY LYNOMIA CANNOT DUPLICATE IT TODAY, measured rather than assumed:
 *
 *   - The whole integration is behind `LYNOMIA_INTEGRATION_ENABLED`, which is **false** in both
 *     `.env` and `.env.example`, so no store can reach it as shipped.
 *   - Its abandoned-cart surface is a READ (`GET api/lynomia/commerce/v1/carts/abandoned`), not a
 *     sender: it hands Lynomia the list, and `CommerceReader::abandonedCarts()` returns rows.
 *   - Its automation vocabulary deliberately does NOT offer a Store Syria merchant the cart
 *     trigger -- `AutomationApiController::rules()`' own docblock records that the trigger's
 *     provider is still PRE_UAT and produces no abandoned carts, and that a free-form message
 *     action is not offered either.
 *
 * SO THE RISK IS PROSPECTIVE, which is exactly what a census is for. The owner's instruction is to
 * preserve one authoritative workflow and prevent FUTURE duplicate messaging integrations, and
 * explicitly not to activate Lynomia's disabled basket trigger. This file fails if a second
 * first-party sender appears, if the kill switch's default flips, or if the cart trigger is
 * enabled -- each with a message saying what decision that would be.
 *
 * IT IS A SOURCE AND ROUTE CENSUS, NOT A BEHAVIOUR TEST. Nothing here sends anything. The
 * behaviour of the one workflow is covered by `NudgeIdempotencyTest`,
 * `AbandonedMessageReportingTest` and `AbandonedCartSendStoreScopeTest`.
 */
final class ShopperMessagingIsOneWorkflowTest extends TestCase
{
    /** The authoritative workflow's routes. A fifth is a finding until it is listed here. */
    private const ROUTES = [
        'abandon-carts-messagesend',
        'abandon-carts-emailsend',
        'abandon-wishlist-message-send',
        'abandon-wish-emailsend',
    ];

    /** The first-party files allowed to send a shopper a marketing message about their basket. */
    private const SENDER_FILES = [
        'app/Http/Controllers/CartController.php',
        'app/Http/Controllers/WishlistController.php',
        'app/Services/AbandonedMessageDispatch.php',
        'app/Services/Marketing/NudgeGate.php',
        'app/Services/Marketing/NudgeLedger.php',
        'app/Services/Marketing/NudgeThrottle.php',
    ];

    /** @return array<string, string> first-party PHP sources, path => code */
    private function firstPartySources(): array
    {
        $sources = [];

        foreach (['app'] as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relative = str_replace(base_path() . '/', '', $file->getPathname());
                    $sources[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        ksort($sources);

        return $sources;
    }

    public function test_the_four_authoritative_routes_all_exist(): void
    {
        $uris = [];

        foreach (app('router')->getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        foreach (self::ROUTES as $uri) {
            $this->assertContains($uri, $uris, sprintf(
                'The authoritative shopper-messaging route %s is gone. If it was removed on '
                . 'purpose, remove it from this census too and say where the workflow lives now.',
                $uri
            ));
        }
    }

    /**
     * NO FIFTH ENDPOINT MESSAGES A SHOPPER ABOUT THEIR BASKET.
     *
     * THE PATTERN NEEDS BOTH A BASKET WORD AND A SEND WORD, and the first version of this test
     * needed only the first -- which matched seven wishlist CRUD routes (a shopper adding an item,
     * the merchant's `Route::resource('wishlist')`, the storefront's wish-list counters) and
     * reported every one of them. That is the failure mode a census dies of: a list of known
     * non-findings trains the next reader to add to the ignore list rather than read the finding.
     *
     * Both halves is what the four real senders have in common and the CRUD routes do not:
     * `abandon-carts-messagesend`, `abandon-carts-emailsend`, `abandon-wishlist-message-send`,
     * `abandon-wish-emailsend`. A future `abandon-cart-smssend` or `wishlist-notify` still matches;
     * `POST wishlist` does not. Writes only -- `abandon-carts-handled` and `abandon-carts-show` are
     * merchant screens, and `abandon-carts-destroy` is a DELETE on the merchant's own list.
     */
    public function test_no_fifth_first_party_route_sends_a_basket_message(): void
    {
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (str_starts_with($uri, 'api/lynomia/')) {
                continue;   // covered by its own assertions below
            }

            $mentionsBasket = preg_match('/abandon|wishlist|wish-|cart/i', $uri) === 1;
            $mentionsSending = preg_match('/message|mail|send|notif|nudge|sms|whatsapp|telegram/i', $uri) === 1;
            $isWrite = (bool) array_intersect(['POST', 'PUT', 'PATCH'], $route->methods());

            if (! $mentionsBasket || ! $mentionsSending || ! $isWrite) {
                continue;
            }

            if (in_array($uri, self::ROUTES, true)) {
                continue;
            }

            $offenders[] = strtoupper(implode('|', array_diff($route->methods(), ['HEAD']))) . ' ' . $uri;
        }

        $this->assertSame([], $offenders, sprintf(
            "A new first-party route writes to an abandoned cart or wishlist surface:\n  %s\n\n"
            . 'MKT-009 asks for ONE authoritative shopper-messaging workflow. If this route sends '
            . 'a shopper a message, it is a second workflow and it must go through `NudgeGate` and '
            . 'be listed in ROUTES here. If it sends nothing, list it as a known non-sender with a '
            . 'word about what it does.',
            implode("\n  ", $offenders)
        ));
    }

    /**
     * NO FIRST-PARTY FILE OUTSIDE THE LISTED SET HANDS A MESSAGE TO A SHOPPER'S PHONE.
     *
     * `Utility::SendMsgs()` is the shared WhatsApp writer and is called from the ORDER and payment
     * paths too -- PaymentService, PaymentController, ApiController, PayU, AuthorizeNet -- which are
     * transactional, not marketing, and are not this census's business. So the census looks for the
     * MARKETING entry points: `AbandonedMessageDispatch::handOver()` and the abandoned-basket
     * e-mail templates.
     */
    public function test_only_the_listed_files_dispatch_an_abandoned_basket_message(): void
    {
        $offenders = [];

        foreach ($this->firstPartySources() as $path => $code) {
            if (in_array($path, self::SENDER_FILES, true)) {
                continue;
            }

            if (preg_match('/->\s*handOver\s*\(/', $code)) {
                $offenders[] = $path . ' calls handOver()';
            }

            if (preg_match("/sendEmailTemplate\s*\(\s*['\"]Abandon /", $code)) {
                $offenders[] = $path . ' sends an Abandon* e-mail template';
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "A file outside the authoritative workflow dispatches an abandoned-basket message:\n  %s\n\n"
            . 'Add it to SENDER_FILES together with its own duplicate guard -- a sender that does '
            . 'not reserve through `NudgeGate` can message a shopper the ledger already covered, '
            . 'which is the duplication MKT-009 exists to prevent.',
            implode("\n  ", $offenders)
        ));
    }

    /**
     * THE LYNOMIA KILL SWITCH DEFAULTS TO FALSE, in the config and in both env files.
     *
     * This is not a statement that the integration is unsafe. It is the premise every "Lynomia
     * cannot duplicate the first-party path today" claim in the traceability matrix rests on, and a
     * premise that stops being true silently is worse than one that was never asserted.
     */
    public function test_the_lynomia_integration_is_off_by_default(): void
    {
        $this->assertFalse(
            (bool) config('lynomiachat.integration_enabled', config('lynomia.integration_enabled', false)),
            'The Lynomia integration now resolves as enabled by default. Enabling it is an owner '
            . 'decision, and the "no duplication today" finding in MKT-009 depends on it being off.'
        );

        foreach (['.env.example'] as $file) {
            $path = base_path($file);

            if (! is_file($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression(
                '/^LYNOMIA_INTEGRATION_ENABLED\s*=\s*false\s*$/mi',
                $contents,
                $file . ' no longer sets LYNOMIA_INTEGRATION_ENABLED=false, so a fresh install '
                . 'would ship with the integration on.'
            );
        }
    }

    /**
     * LYNOMIA'S ABANDONED-CART SURFACE IS A READ, NOT A SENDER, and must stay one.
     *
     * The owner's instruction is explicit: do not activate Lynomia's disabled basket trigger. The
     * route is a GET that hands Lynomia the list; if it ever gained a write method, or if the
     * module gained a sender, that would be the second messaging workflow MKT-009 forbids.
     */
    public function test_lynomias_abandoned_cart_surface_stays_read_only(): void
    {
        $found = false;

        foreach (app('router')->getRoutes() as $route) {
            if (! str_contains($route->uri(), 'lynomia/commerce') || ! str_contains($route->uri(), 'abandoned')) {
                continue;
            }

            $found = true;
            $methods = array_diff($route->methods(), ['HEAD']);

            $this->assertSame(['GET'], array_values($methods), sprintf(
                'Lynomia\'s abandoned-cart route %s now accepts %s. It is a READ that hands Lynomia '
                . 'the list; a write method here would make the module a second shopper-messaging '
                . 'path, which the owner has explicitly not authorised.',
                $route->uri(),
                implode('|', $methods)
            ));
        }

        $this->assertTrue($found,
            'Lynomia\'s abandoned-cart read route is gone. If the surface moved, re-point this '
            . 'census at it rather than deleting the assertion.');
    }

    /**
     * THE CART TRIGGER IS STILL NOT OFFERED TO A MERCHANT, as its own docblock records.
     *
     * `AutomationApiController::rules()` states that a Store Syria merchant is offered neither the
     * cart trigger (its provider is PRE_UAT and produces no abandoned carts) nor a free-form
     * message action. That sentence is the evidence for "no duplication today", so this asserts the
     * sentence is still there -- and the comment stripper is used so a code change cannot be hidden
     * behind a comment that still says the old thing.
     */
    public function test_the_lynomia_cart_trigger_is_still_not_offered(): void
    {
        $path = base_path('packages/workdo/LynomiaChat/src/app/Http/Controllers/AutomationApiController.php');

        $this->assertFileExists($path,
            'LynomiaChat\'s automation controller is gone; re-measure whether the cart trigger is '
            . 'now reachable before deleting this test.');

        $code = (string) file_get_contents($path);

        $this->assertStringContainsString('is offered neither the cart trigger', $code,
            'The recorded reason that a Store Syria merchant cannot use Lynomia\'s cart trigger is '
            . 'no longer in the source. Either the trigger is now offered -- which is an owner '
            . 'decision the instruction explicitly withholds -- or the note was lost. Re-measure.');
    }
}
