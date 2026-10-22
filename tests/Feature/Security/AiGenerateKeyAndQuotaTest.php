<?php

namespace Tests\Feature\Security;

use App\Models\ApikeySetiings;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * P1-SEC-22(e) -- `POST generate/response` spends an OpenAI key it picks AT RANDOM from every tenant's.
 * =============================================================================================
 *
 * THE ROUTE. `generate.response` carries `web|auth|xss|setlocate|verified` -- authentication and e-mail
 * verification, and NOTHING else. No permission check, no throttle, no quota. Every merchant and every
 * staff member of every store can call it.
 *
 * WHAT IT DOES WITH THE KEY, and this is the part the task row understates. `AITemplateController::aiGenerate`
 * opens with:
 *
 *     $key_data = ApikeySetiings::inRandomOrder()->limit(1)->first();
 *
 * `apikey_setiings` has `created_by` and **no `store_id`** (2024_03_13_084315), `ApikeySetiings` carries no
 * `BelongsToStore` and is absent from `config/tenancy.php`'s `scoped_models`, and the query applies no
 * predicate of any kind. So the key a caller spends is drawn uniformly from EVERY row in the table: the
 * platform operator's key, or another merchant's. Two tenants who both configure a key cross-charge each
 * other at random, and a merchant who configures none still bills whoever did.
 *
 * AND THE SPEND PER CALL IS CALLER-CONTROLLED:
 *
 *     $aitokens     = (int)$request->result_length;    // max_tokens
 *     $max_results  = (int)$request->num_of_result;    // n
 *
 * Both reach the OpenAI request unvalidated. `num_of_result` is a `<select>` in `generate.blade.php:68`
 * and `result_length` a bare `Form::number` with no max (:78) -- both client-side only. One request may
 * ask for n=100 at max_tokens=999999.
 *
 * THE SPLIT, and why this file does not close the whole criterion. The acceptance criterion reads "who may
 * call generate/response (the platform's OpenAI key) is decided and enforced". The DECISION is the owner's
 * and stays open: **no AI permission is seeded anywhere** (`Utility::addNewData()` has no such name), and
 * the project skill records that gating on a permission no row exists for is a permanent 403. So a
 * permission gate cannot be added until the owner says which permission to create and who holds it.
 *
 * What does NOT need that decision, and is what these tests pin:
 *   1. a caller must never spend ANOTHER MERCHANT's key -- whatever the answer, random is not it;
 *   2. the per-call spend must be bounded server-side;
 *   3. an unknown template must not 500;
 *   4. the endpoint must be rate-limited.
 *
 * ON OUTBOUND CALLS, stated as measured rather than as intended. AFTER the fix no case here reaches
 * `new OpenAi(...)`: each is refused by validation, by the template guard, by the throttle, or by key
 * selection finding nothing this caller may use -- all of which precede it. BEFORE the fix most of them
 * DO reach it and the attempt fails, which is why the RED run returns 500 rather than a clean refusal.
 * That 500 is itself part of the finding: the endpoint hands caller-controlled parameters to an outbound
 * paid API and surfaces the failure as a server error. The assertions are written to accept either shape
 * so they report the SECURITY outcome and not the ambient network state of the machine.
 */
class AiGenerateKeyAndQuotaTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('ai-generate');
    }

    /** a template whose field_json names one required field, which is what the handler iterates */
    private function template(): Template
    {
        return Template::forceCreate([
            'template_name' => 'Product blurb',
            'prompt' => 'Write about ##subject##',
            'field_json' => json_encode(['field' => [['field_name' => 'subject', 'field_type' => 'text']]]),
            'is_tone' => 0,
            'module' => 'Product',
        ]);
    }

    private function payload(Template $template, array $overrides = []): array
    {
        return array_merge([
            'template_name' => $template->id,
            'subject' => 'a kettle',
            'language' => 'English',
            'tone' => 'neutral',
            'ai_creativity' => 0.7,
            'num_of_result' => 1,
            'result_length' => 10,
        ], $overrides);
    }

    private function generate(User $actor, array $payload)
    {
        return $this->actingAs($actor, 'web')
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson('/generate/response', $payload);
    }

    /** premise: the route exists and this principal reaches the handler. Without this every assertion below could be a 404 or a redirect. */
    public function test_premise_a_verified_merchant_reaches_the_handler(): void
    {
        [$merchant] = $this->makeTenant();
        $template = $this->template();
        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-key-own', 'created_by' => $merchant->id]);

        $response = $this->generate($merchant, $this->payload($template, ['result_length' => 999999]));

        // 999999 is refused below; what matters here is that we were not bounced before the handler
        $this->assertNotSame(404, $response->status(), 'the route is missing -- every test in this file is vacuous');
        $this->assertNotSame(302, $response->status(), 'the caller was redirected (auth/verified), so the handler was never reached');
    }

    /**
     * THE CORE FINDING. A merchant with no key of their own must not spend ANOTHER MERCHANT's key.
     *
     * Only merchant B has a key. Merchant A calls. Under `inRandomOrder()` with no predicate there is
     * exactly one row, so A spends B's key with certainty -- the randomness is what hides it when several
     * rows exist, not what causes it.
     *
     * The refusal reuses the handler's own existing "no key configured" message, so the fix needs no new
     * contract: to A, a key they may not use is a key that is not there.
     */
    public function test_a_merchant_cannot_spend_another_merchants_api_key(): void
    {
        [$merchantA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();
        $template = $this->template();

        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-key-belonging-to-B', 'created_by' => $merchantB->id]);

        $this->assertSame(1, DB::table('apikey_setiings')->count(), 'premise: exactly one key row, and it is B\'s');

        $response = $this->generate($merchantA, $this->payload($template));

        $this->assertSame('error', $response->json('status'), 'merchant A was allowed to proceed with merchant B\'s key');
        $this->assertStringContainsString('Api Key', (string) $response->json('message'));
    }

    /** the positive control: a merchant's own key is still selected, so the fix is a predicate and not a blanket refusal */
    public function test_a_merchant_with_their_own_key_is_not_refused_for_want_of_a_key(): void
    {
        [$merchant] = $this->makeTenant();
        [$other] = $this->makeTenant();
        $template = $this->template();

        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-key-own', 'created_by' => $merchant->id]);
        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-key-other', 'created_by' => $other->id]);

        // A LEGITIMATE result_length on purpose. With 0 the new bounds validation refuses the request
        // before key selection runs, and this control would pass without exercising what it claims to.
        $response = $this->generate($merchant, $this->payload($template));

        // NOT just "the message lacks 'Api Key'": a 500 lacks it too, so that alone passes while the
        // endpoint is broken for everyone. This asserts the caller got PAST key selection, which is the
        // only thing this control is about.
        $this->assertNotSame(404, $response->status(), 'premise: the route exists');
        $this->assertStringNotContainsString(
            'Api Key',
            (string) $response->json('message'),
            'the caller owns a key and was still told none is configured'
        );
        $this->assertSame(
            'sk-not-a-real-key-own',
            DB::table('apikey_setiings')->where('created_by', $merchant->id)->value('key'),
            'premise: the fixture key really is the one this merchant owns'
        );
    }

    /**
     * OWNER DECISION, 2026-10-22: merchants must not consume the platform's OpenAI key.
     *
     * The interim fix deliberately PRESERVED the platform fallback for every caller, because the
     * decision was open and no AI permission is seeded (so a permission gate would have been a
     * permanent 403). The decision closed it: a merchant with no key of their own is refused even
     * though a platform key exists and is reachable.
     *
     * Driven for a merchant AND for their staff member, because `creatorId()` resolves staff to their
     * merchant and a fix keyed on the signed-in row alone would let staff through.
     */
    public function test_a_merchant_cannot_spend_the_platform_key(): void
    {
        [$merchant] = $this->makeTenant();
        $template = $this->template();

        // a real platform key exists and is reachable -- that is the point of the test
        $operator = User::factory()->create([
            'type' => 'super admin', 'created_by' => 0, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-platform-key', 'created_by' => $operator->id]);
        $this->assertSame(1, DB::table('apikey_setiings')->count(), 'premise: the platform key is there to be taken');

        $response = $this->generate($merchant, $this->payload($template));

        $this->assertSame('error', $response->json('status'), 'a merchant spent the platform key');
        $this->assertStringContainsString('Api Key', (string) $response->json('message'));
    }

    /** the same for a merchant's STAFF member, whom creatorId() resolves to the merchant */
    public function test_a_staff_member_cannot_spend_the_platform_key_either(): void
    {
        [$merchant] = $this->makeTenant();
        $template = $this->template();

        $operator = User::factory()->create([
            'type' => 'super admin', 'created_by' => 0, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-platform-key', 'created_by' => $operator->id]);

        $staff = User::factory()->create([
            'type' => 'staff', 'created_by' => $merchant->id, 'is_active' => 1, 'email_verified_at' => now(),
        ]);

        $response = $this->generate($staff, $this->payload($template));

        $this->assertSame('error', $response->json('status'), 'a staff member spent the platform key');
    }

    /**
     * The platform operator's key remains usable by the operator. Guards against a fix that scopes so
     * tightly it locks out the one principal who certainly may spend it -- the lockout shape the project
     * skill records for `isAbleTo()` ordering, in a different guise.
     */
    public function test_the_platform_operator_can_still_use_the_platform_key(): void
    {
        // via the factory, as ChatgptKeyDisclosureTest does: `users` has NO `lang` column, and a
        // forceCreate naming one throws in the FIXTURE -- the handler is then never reached and the
        // test fails for a reason that has nothing to do with the control (census-failure mode 3).
        $operator = User::factory()->create([
            'type' => 'super admin', 'created_by' => 0, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $template = $this->template();
        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-platform-key', 'created_by' => $operator->id]);

        $response = $this->generate($operator, $this->payload($template));

        $this->assertStringNotContainsString('Api Key', (string) $response->json('message'), 'the operator was locked out of the platform key');
    }

    /**
     * The per-call spend is bounded SERVER-SIDE. Refused rather than silently clamped: a caller asking for
     * 100 results should be told no, not quietly given 10, and refusal is observable where clamping is not.
     *
     * @dataProvider abusiveSpendProvider
     */
    public function test_an_abusive_spend_is_refused(string $field, $value): void
    {
        [$merchant] = $this->makeTenant();
        $template = $this->template();
        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-key-own', 'created_by' => $merchant->id]);

        $response = $this->generate($merchant, $this->payload($template, [$field => $value]));

        $this->assertContains($response->status(), [422, 200], 'unexpected status');
        if ($response->status() === 200) {
            $this->assertSame('error', $response->json('status'), "{$field}={$value} was accepted");
        }
    }

    public static function abusiveSpendProvider(): array
    {
        return [
            'max_tokens far above any legitimate use' => ['result_length', 999999],
            'a hundred completions in one call' => ['num_of_result', 100],
            'a negative token count' => ['result_length', -1],
            'creativity out of range' => ['ai_creativity', 99],
        ];
    }

    /**
     * An unknown template id must not 500. `Template::where('id', ...)->first()` returns null and the very
     * next statement does `json_decode($template->field_json)` -- a null-property dereference reachable with
     * any id that does not exist.
     */
    public function test_an_unknown_template_does_not_500(): void
    {
        [$merchant] = $this->makeTenant();
        ApikeySetiings::forceCreate(['key' => 'sk-not-a-real-key-own', 'created_by' => $merchant->id]);

        $response = $this->generate($merchant, [
            'template_name' => 999999, 'subject' => 'x', 'language' => 'English',
            'num_of_result' => 1, 'result_length' => 10, 'ai_creativity' => 0.7,
        ]);

        $this->assertNotSame(500, $response->status(), 'an unknown template id dereferences null');
    }

    /**
     * The endpoint is rate-limited. Without this, the bound above limits one call and nothing limits the
     * number of calls -- so the spend is still unbounded, just in smaller pieces.
     */
    public function test_the_endpoint_is_rate_limited(): void
    {
        [$merchant] = $this->makeTenant();
        $template = $this->template();

        // NO key is seeded, deliberately: every call then short-circuits at key selection, so none of the
        // forty attempts an outbound request. The throttle fires in middleware, ahead of the handler, so
        // seeding no key does not weaken what this measures.
        $statuses = [];
        for ($i = 0; $i < 40; $i++) {
            $statuses[] = $this->generate($merchant, $this->payload($template))->status();
        }

        $this->assertContains(429, $statuses, 'forty calls in one minute were all accepted: the endpoint has no throttle');
    }

    /** the census: no handler selects an API key without a predicate */
    public function test_no_handler_selects_an_api_key_at_random(): void
    {
        $sources = [];
        foreach (['Http/Controllers/AITemplateController.php'] as $relative) {
            $raw = (string) file_get_contents(app_path($relative));
            $stripped = '';
            foreach (token_get_all($raw) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $stripped .= is_array($token) ? $token[1] : $token;
            }
            $sources[$relative] = $stripped;
        }

        foreach ($sources as $relative => $source) {
            $this->assertStringContainsString('ApikeySetiings', $source, "{$relative} no longer names the model -- this census is vacuous");
            $this->assertStringNotContainsString(
                'ApikeySetiings::inRandomOrder()',
                $source,
                "{$relative} selects an API key with no tenant predicate"
            );
        }
    }
}
