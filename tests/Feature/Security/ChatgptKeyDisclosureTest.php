<?php

namespace Tests\Feature\Security;

use App\Models\ApikeySetiings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-19 -- the platform's shared ChatGPT/OpenAI keys were rendered to every logged-in user.
 * =============================================================================================
 *
 * `POST /setting-form` (route `setting.form`, group `auth, xss, setlocate, verified`) renders a settings
 * tab as JSON-wrapped HTML. For `tab_type=chat_gpt_setting` it ran `ApikeySetiings::get()` -- the model
 * has no tenant column and is not in `config/tenancy.php` -- and the view printed every row's `key` into
 * an `<input value>`. The tab LINK is shown to a platform operator only (resources/views/setting/
 * index.blade.php:72-113) and the SAVE handler (`ChatgptSettings`) refuses everyone else, but the
 * READ handler had no check at all, so a merchant or a merchant's staff member who POSTed the tab name
 * received the keys. They are the platform's, shared by every tenant (`AITemplateController` picks one
 * at random for whoever asks), and spending them is billed to the platform.
 *
 * MEASURED before the fix, through the real route: merchant, staff and operator each received the key.
 *
 * `currency_rates` has no migration (GATE F -- docs/GATE-F-SCHEMA-EXPORT.md): `settingForm` seeds default
 * rows into it before it dispatches on the tab, so without the table EVERY tab answers "Something went
 * wrong" and a "no key in the response" assertion would pass for that reason alone. The table is created
 * here, and the operator control below proves the tab really rendered.
 *
 * Sentinels carry `FIXTURE` so the CI secret scan's generic-assigned-secret rule exempts them.
 */
class ChatgptKeyDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PLATFORM_KEY = 'sk-FIXTURE-platform-openai-key-0001';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('currency_rates')) {
            Schema::create('currency_rates', function ($t) {
                $t->id();
                $t->unsignedBigInteger('store_id')->nullable();
                $t->string('currency_code');
                $t->string('currency_name')->nullable();
                $t->string('symbol')->nullable();
                $t->decimal('rate_per_usd', 20, 8)->default(1);
                $t->boolean('is_active')->default(1);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    private function operatorWithKey(): User
    {
        $operator = User::factory()->create([
            'type' => 'super admin', 'created_by' => 0, 'is_active' => 1, 'email_verified_at' => now(),
        ]);

        // Through the model, not DB::table(): ApikeySetiings is Cachable, and a raw insert after an
        // earlier read would leave Eloquent serving the pre-write rows.
        ApikeySetiings::create(['key' => self::PLATFORM_KEY, 'created_by' => $operator->id]);

        return $operator;
    }

    /** @return array{0: User, 1: User} a merchant and one of the merchant's staff */
    private function merchantAndStaff(): array
    {
        [$merchant] = $this->makeTenant();
        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'gpt-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();

        return [$merchant, $this->makeStaff($merchant)];
    }

    private function tab(User $as, string $tab)
    {
        return $this->actingAs($as)->post('/setting-form', ['tab_type' => $tab]);
    }

    private function content($response): string
    {
        return (string) (json_decode($response->getContent(), true)['data']['content'] ?? '');
    }

    /** THE PREMISE: the tab renders, and the key is in it, for the principal who is meant to see it. */
    public function test_the_operator_still_gets_the_key_tab(): void
    {
        $operator = $this->operatorWithKey();

        $response = $this->tab($operator, 'chat_gpt_setting');

        $response->assertOk();
        $this->assertTrue((bool) json_decode($response->getContent(), true)['is_success'], 'Premise: the tab did not render. ' . substr($response->getContent(), 0, 200));
        $this->assertStringContainsString(self::PLATFORM_KEY, $this->content($response), 'The operator must still see the platform keys they manage.');
    }

    public function test_a_merchant_does_not_receive_the_platform_keys(): void
    {
        $this->operatorWithKey();
        [$merchant] = $this->merchantAndStaff();

        $response = $this->tab($merchant, 'chat_gpt_setting');

        $this->assertStringNotContainsString(self::PLATFORM_KEY, $response->getContent(), 'The platform OpenAI key was returned to a merchant.');
        $this->assertStringNotContainsString('sk-FIXTURE', $response->getContent());
        $this->assertSame(403, $response->getStatusCode(), 'Expected a non-401 refusal (the shipped app forces a logout on 401).');
    }

    public function test_a_merchants_staff_member_does_not_receive_the_platform_keys(): void
    {
        $this->operatorWithKey();
        [, $staff] = $this->merchantAndStaff();

        $response = $this->tab($staff, 'chat_gpt_setting');

        $this->assertStringNotContainsString(self::PLATFORM_KEY, $response->getContent(), 'The platform OpenAI key was returned to a merchant\'s staff member.');
        $this->assertSame(403, $response->getStatusCode());
    }

    /** The refusal is about THIS tab; every other tab a merchant uses is untouched. */
    public function test_a_merchant_can_still_load_their_other_settings_tabs(): void
    {
        $this->operatorWithKey();
        [$merchant] = $this->merchantAndStaff();

        foreach (['email_setting', 'currency_setting', 'payment_setting'] as $tab) {
            $response = $this->tab($merchant, $tab);

            $response->assertOk();
            $this->assertTrue((bool) json_decode($response->getContent(), true)['is_success'], "The {$tab} tab stopped rendering for a merchant. " . substr($response->getContent(), 0, 200));
            $this->assertStringNotContainsString(self::PLATFORM_KEY, $this->content($response), "{$tab} carries the platform key.");
        }
    }
}
