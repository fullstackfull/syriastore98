<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\PaymentController;
use App\Services\OrderCompleteBinding;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * F185 — THE PAYMERA TRIGGER MUST NOT HAND A STRANGER ANOTHER TENANT'S ORDER NUMBER.
 * =============================================================================================
 *
 * Three branches of `handlePaymeraTrigger` answered `['product_order_id' => $pending[...]]` with no
 * test of who was asking: the already-`processed` branch, the in-lock re-check-returned-null
 * branch, and the F96 claim refusal. The browser callback's equivalent branches DO test it —
 * P1-ORD-13 added `OrderCompleteBinding::holds()` there precisely so a replayed reference could not
 * disclose the buyer's order. The trigger never got the same test.
 *
 * IT IS DEFENCE IN DEPTH, NOT A LIVE DISCLOSURE, and the first version of this docblock claimed
 * otherwise. The mandated review traced the reachability: `product_order_id` is only ever written
 * in the same `Cache::put` as `status = 'A'`, and the trigger's FIRST guard returns on
 * `status === 'A'` without that field — so `product_order_id !== null` implies the request already
 * returned, and all three branches answer null today. The already-`processed` branch needs
 * `processed === true` with `status !== 'A'`, which no writer produces; the other two read the
 * pre-lock record, which passed that same test. The only conceivable live limb is a record left by
 * an older deployment whose callback set `processed` without `status`, which is speculative and
 * bounded by a two-hour record life.
 *
 * So what these cases buy is that the field stays null if a future edit makes it non-null there —
 * a third writer, a reordered guard. "Correct by construction today, kept correct by a test" is
 * the honest claim. Overclaiming is the failure this project has corrected twice by measurement,
 * and this file was the third time.
 *
 * WHO WOULD BE ASKING, if it became reachable: the route is inside
 * `['auth','xss','setlocate','verified']` (F97), so any logged-in `web` user who names a live
 * `uniqid('paymera_')` reference. **Not the buyer** — `auth` uses the `web` guard and a shopper is
 * a `Customer`, so the buyer's browser is redirected to /login exactly like the gateway. The
 * buyer's path is the callback, which already had this check.
 *
 * WHY IT WAS RECORDED RATHER THAN FIXED, AND WHY THAT IS NOW ANSWERED. The objection was that the
 * trigger exists for the GATEWAY, which holds no session, so `holds()` would be false and a machine
 * caller would stop receiving the number. But a machine caller cannot reach the handler at all:
 * `auth` redirects it to /login first. That is F97's own measurement, kept by the ratchet above —
 * so every caller that reaches this code has a `web` session, and `holds()` is exactly the right
 * question. Measured across both repositories as well: nothing calls `store/paymera/trigger/*` —
 * not `resources/`, not `public/js/`, and the Flutter admin app contains no Paymera reference at
 * all. The compatibility question is answered by measurement, not by "probably free".
 *
 * THE RESIDUAL IS TIED TO THAT RATCHET. Move the route out of `auth` — the functional half of F97,
 * an owner decision — and a real gateway POST arrives with no session and is refused the number.
 * The two changes have to be re-derived together, which is why this file asserts the `auth` gate
 * as its own premise rather than trusting the sibling file to.
 */
class PaymeraTriggerOrderNumberTest extends TestCase
{
    private const REFERENCE = 'paymera_f185probe';

    /**
     * THE PREMISE THE WHOLE FIX RESTS ON, asserted here and not borrowed.
     *
     * If this route ever stops being authenticated, `holds()` becomes the wrong question — a
     * sessionless gateway would be refused the number it is entitled to — and this slice has to be
     * re-derived alongside F97.
     */
    public function test_the_trigger_route_is_still_authenticated(): void
    {
        /*
         * DERIVED FROM THE ACTION, NOT THE URI, which review's F7 asked for and is right. A first
         * cut matched `str_contains($r->uri(), 'paymera/trigger')` and took `->first()`. That
         * cannot see the realistic way this premise breaks: an ADDITIONAL declaration — a GET on
         * the same path, or a differently-prefixed one — pointing at the same action. Asking "every
         * route whose action is handlePaymeraTrigger" catches all of them.
         */
        $routes = collect(app('router')->getRoutes())->filter(
            fn ($r) => str_contains((string) ($r->getActionName() ?? ''), '@handlePaymeraTrigger')
        );

        $this->assertNotEmpty(
            $routes,
            'no route points at handlePaymeraTrigger any more; re-derive F185 and F97'
        );

        foreach ($routes as $route) {
            $this->assertContains(
                'auth',
                $route->gatherMiddleware(),
                'a route reaching handlePaymeraTrigger is not behind `auth` (' . $route->methods()[0]
                . ' ' . $route->uri() . '), so a sessionless gateway can reach it — and'
                . ' `OrderCompleteBinding::holds()`, which F185 applies on three branches, would'
                . ' refuse that gateway the number it is entitled to. Re-derive both together.'
            );
        }
    }

    /** A session that did place the order still gets its number. The compatibility half. */
    public function test_the_session_that_placed_the_order_still_receives_its_number(): void
    {
        $this->bindSession();
        OrderCompleteBinding::remember(4242);

        $answer = $this->triggerAnswer([
            'order_id' => 4242,
            'product_order_id' => '0202501010101',
        ]);

        $this->assertSame(
            '0202501010101',
            $answer['product_order_id'],
            'the buyer polling their own payment was refused their own order number, which is a'
            . ' regression and not a fix'
        );
        $this->assertSame('A', $answer['status'], 'the response shape changed');
        $this->assertTrue($answer['ok'], 'the response shape changed');
    }

    /** A session that did not place it gets null — and cannot tell that from "no order yet". */
    public function test_a_stranger_is_refused_the_order_number(): void
    {
        $this->bindSession();
        OrderCompleteBinding::remember(1);

        $answer = $this->triggerAnswer([
            'order_id' => 4242,
            'product_order_id' => '0202501010101',
        ]);

        $this->assertNull(
            $answer['product_order_id'],
            'a logged-in user who named a live payment reference was handed another tenant\'s order'
            . ' number — which is F185'
        );
    }

    /**
     * THE SHAPE IS UNCHANGED, which is the compatibility rule this project runs under.
     *
     * `product_order_id` stays PRESENT and carries null, rather than being omitted. A client
     * reading `response['product_order_id']` sees a null, not a missing key.
     */
    public function test_the_refusal_keeps_every_key_the_success_answer_has(): void
    {
        $this->bindSession();
        OrderCompleteBinding::remember(1);
        $refused = $this->triggerAnswer(['order_id' => 4242, 'product_order_id' => 'X']);

        OrderCompleteBinding::remember(4242);
        $allowed = $this->triggerAnswer(['order_id' => 4242, 'product_order_id' => 'X']);

        $this->assertSame(
            array_keys($allowed),
            array_keys($refused),
            'the refusal answers a different shape from the success, so a client can tell them'
            . ' apart — and a client parsing the success shape may break on the refusal'
        );

        /*
         * AND THE BRANCH THAT ACTUALLY RUNS IN PRODUCTION IS A THIRD SHAPE, which review pointed
         * out this case did not cover. Both arms above come from one `response()->json()` literal,
         * so comparing them to each other cannot fail — it is the live branch that makes the
         * comparison worth anything. `status === 'A'` returns `{status, ok}` and omits
         * `product_order_id` ENTIRELY, so a client reading that key already has to tolerate it
         * being absent. That is why adding a null here cannot break one.
         */
        /*
         * SCOPED TO `handlePaymeraTrigger`'S OWN SOURCE, because the whole-file version could not
         * fail: `if ($pending['status'] === 'A')` appears in the browser callback and in the two
         * unrouted twins as well, so deleting the trigger's own copy left the assertion green.
         * Caught by biting it.
         */
        $live = $this->triggerSource();

        $this->assertStringContainsString(
            "if (\$pending['status'] === 'A') {",
            $live,
            'the `status === A` short-circuit is gone. It is what makes the three guarded branches'
            . ' unreachable with a non-null order number today, so F185 stops being defence in'
            . ' depth and becomes live — re-derive the severity.'
        );

        $this->assertStringContainsString(
            "return response()->json(['status' => 'A', 'ok' => true]);",
            $live,
            'the live short-circuit no longer answers without `product_order_id`, so a client'
            . ' reading that key no longer has to tolerate its absence — and the shape argument'
            . ' above stops holding'
        );
    }

    /**
     * NO SESSION AT ALL — refused, and it does not raise. This is the Q5 residual's own case.
     *
     * A first cut called `bindSession()` here, which installs a real session store and made this a
     * duplicate of the stranger case above. Review caught it. Without it this covers what it says:
     * `OrderCompleteBinding` writes through the `session()` helper, which resolves the driver from
     * the container rather than the request, so a `get` on an unstarted store returns the default
     * and `holds()` degrades to **refuse** rather than to a 500. That is what makes the helper safe
     * if the route ever leaves the `web` group.
     */
    public function test_no_session_is_refused_rather_than_erroring(): void
    {
        $answer = $this->triggerAnswer(['order_id' => 4242, 'product_order_id' => 'X']);

        $this->assertNull(
            $answer['product_order_id'],
            'with no session at all the helper handed out the number, or raised. Either way it is'
            . ' not safe outside the `web` group, which is the residual this case exists for.'
        );
    }

    /**
     * ALL THREE BRANCHES, SOURCE-ASSERTED.
     *
     * The handler cannot be driven end to end (it polls the gateway over HTTP and then runs the
     * order writer), so what a test can do is pin that no branch answers the number directly any
     * more. The literal below is the one all three used.
     */
    public function test_no_trigger_branch_answers_the_order_number_directly(): void
    {
        $source = $this->executableSource();

        /*
         * A REGEX RATHER THAN AN EXACT LITERAL, which review asked for and the sibling file already
         * records as a trap it was caught by: `$pending["product_order_id"]`, a dropped trailing
         * comma or different spacing all defeat a `substr_count` on one spelling.
         */
        $this->assertSame(
            0,
            preg_match_all(
                '/[\'"]product_order_id[\'"]\s*=>\s*\$pending\s*\[\s*[\'"]product_order_id[\'"]\s*\]/',
                $source
            ),
            'a trigger branch answers the stored order number without asking whose session this is.'
            . ' All of them must go through paymeraTriggerAnswer().'
        );

        /*
         * A FLOOR, NOT AN EXACT COUNT. Review's point: `assertSame(3, …)` turns a legitimate fourth
         * call site into a red build. What matters is that no branch answers the field directly —
         * which the assertion above pins — and that the helper is actually used.
         */
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($source, '$this->paymeraTriggerAnswer($pending, $orderId)'),
            'fewer than the three branches F185 names answer through the helper — the'
            . ' already-processed branch, the in-lock re-check, and the F96 claim refusal'
        );
    }

    private function triggerAnswer(array $pending): array
    {
        $m = new \ReflectionMethod(PaymentController::class, 'paymeraTriggerAnswer');
        $m->setAccessible(true);

        return json_decode(
            $m->invoke($this->controller(), $pending, self::REFERENCE)->getContent(),
            true
        );
    }

    private function bindSession(): void
    {
        $request = \Illuminate\Http\Request::create('/store/paymera/trigger/x', 'POST');
        $request->setLaravelSession(app('session.store'));
        $this->app->instance('request', $request);
    }

    /**
     * Just `handlePaymeraTrigger`'s body, comments stripped.
     *
     * A whole-file match is the wrong instrument for a claim about ONE method: the guard this
     * asserts also exists in the browser callback and in the two unrouted twins, so the file-level
     * version stayed green when the trigger's own copy was deleted.
     */
    private function triggerSource(): string
    {
        $m = new \ReflectionMethod(PaymentController::class, 'handlePaymeraTrigger');

        $body = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $out = '';

        foreach (token_get_all('<?php ' . $body) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** See PaymeraCapturedWithoutOrderTest::executableSource(). */
    private function executableSource(): string
    {
        $out = '';

        foreach (token_get_all(file_get_contents(
            (new \ReflectionClass(PaymentController::class))->getFileName()
        )) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function controller(): PaymentController
    {
        return new PaymentController(app(PaymentService::class));
    }
}
