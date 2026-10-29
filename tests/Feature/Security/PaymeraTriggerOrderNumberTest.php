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
 * WHO COULD READ IT. The route is inside `['auth','xss','setlocate','verified']` (F97, pinned by
 * `PaymeraCallbackIdempotencyTest::test_the_paymera_trigger_route_is_still_behind_auth`), so the
 * caller is **any logged-in `web` user** — any merchant, any staff member, any tenant — who names a
 * live payment reference. References are `uniqid('paymera_')`, microtime-derived.
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
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => str_contains((string) $r->uri(), 'paymera/trigger')
        );

        $this->assertNotNull($route, 'the paymera trigger route is gone; re-derive F185 and F97');

        $this->assertContains(
            'auth',
            $route->gatherMiddleware(),
            'the paymera trigger is no longer behind `auth`, so a sessionless gateway can reach it —'
            . ' and `OrderCompleteBinding::holds()`, which F185 applies on three branches, would'
            . ' refuse that gateway the order number it is entitled to. Re-derive both together.'
        );
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
    }

    /** No session at all is refused too, and does not raise. */
    public function test_no_session_is_refused_rather_than_erroring(): void
    {
        $this->bindSession();

        $answer = $this->triggerAnswer(['order_id' => 4242, 'product_order_id' => 'X']);

        $this->assertNull($answer['product_order_id'], 'a caller with no binding got the number');
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

        $this->assertSame(
            0,
            substr_count($source, "'product_order_id' => \$pending['product_order_id'] ?? null,"),
            'a trigger branch answers the stored order number without asking whose session this is.'
            . ' All three must go through paymeraTriggerAnswer().'
        );

        $this->assertSame(
            3,
            substr_count($source, '$this->paymeraTriggerAnswer($pending, $orderId)'),
            'the three branches F185 names must all answer through the one helper — the'
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
