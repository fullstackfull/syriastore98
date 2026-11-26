<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Support\Platform\PlatformContentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A MERCHANT COULD REPLACE THE PUBLISHED TEXT OF THE COMPANY'S OWN WEBSITE, INCLUDING ITS LEGAL
 * DOCUMENTS, WITH ONE ORDINARY POST.
 *
 * Found by the independent authorization review of GAP-104, against a docblock in the change that
 * asserted the opposite — "two independent reasons a merchant cannot write that row". Both reasons
 * were wrong, and the chain is four links, each verified in the source before this test was
 * written:
 *
 *   1. `getCurrentStore()` returns NULL for a merchant with no store (`helper.php:881-895`) — a
 *      supported product state with its own existing test fixture. The operator can never reach
 *      that branch: `type === 'super admin'` returns the literal 1 two lines earlier. So this is
 *      an escalation, not a collision an operator could stumble into.
 *   2. `SettingController::CookieSettings` takes `$request->all()` and writes one row per posted
 *      KEY, matching on `['name' => $key, 'store_id' => getCurrentStore()]`. Its validator is
 *      assigned and never inspected, so none of its "required" fields is required.
 *      `AppSettingController::seoSettings` has the same shape. Both are HOST routes needing no
 *      module and no permission.
 *   3. Illuminate turns `where(['store_id' => null])` into `whereNull`, so that lookup matches the
 *      platform's own row. This repository already records that exact mechanic at
 *      `routes/web.php:331-334` for a different handler.
 *   4. `SettingOwnership::keep()` protects only the CREATE path. On UPDATE it RESTORES the
 *      original `created_by` — so the merchant's write keeps the operator's ownership stamp and
 *      the reader's `where('created_by', $operatorId)` predicate still matches it. The control the
 *      change cited as protection is what makes the hijack land.
 *
 * WHAT IT IS AND IS NOT. The value still renders through Blade escaping or the Markdown renderer,
 * and `xss` runs on both host routes, so this is not script injection. It is content, brand and
 * LEGAL-TEXT integrity: a third party publishing terms of service at the company's address. For
 * documents that bind the business that is worse than a defacement, not better.
 *
 * THE UNDERLYING WEAKNESS IS PRE-EXISTING; THE CONSEQUENCE IS NEW. Those settings writers have
 * always taken arbitrary names. What GAP-104 added is a reader that publishes one of those names
 * on the company's public site, which is what turns a sloppy write into a publishing channel —
 * so the guard belongs with the change that created the consequence.
 *
 * THE FIX IS IN THE `saving` HOOK, NOT IN THE HANDLERS, and deliberately so: every Eloquent writer
 * of `settings` passes through `Setting::booted()`, including the ten vendored module controllers
 * with the same shape that must not be edited.
 */
class PlatformSiteContentWriteGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const EN = PlatformContentStore::NAME_PREFIX . 'en';

    /**
     * The operator's published row, as it exists once the owner has used the editor once. The
     * exploit needs this to exist — it hijacks a row rather than creating one.
     */
    private function operatorPublishes(string $headline): int
    {
        $operator = $this->makePlatformAdmin();

        /*
         * ACTING AS THE OPERATOR TO PLANT THE FIXTURE, because the guard is fail-closed with NO
         * principal at all and refuses an unauthenticated write — which is deliberate (a console
         * command or queued job must not be able to publish either). My first version of this
         * helper planted the row with no actor and was refused by the very guard it exists to
         * test, which is the guard behaving correctly and the fixture being wrong.
         */
        $this->actingAs($operator);

        Setting::withoutGlobalScopes()->create([
            'name' => self::EN,
            'value' => json_encode(['home.hero.title' => $headline]),
            'store_id' => null,
            'created_by' => $operator->id,
        ]);

        return (int) $operator->id;
    }

    /** Guards every assertion below: the exploit's preconditions must really hold. */
    #[Test]
    public function test_the_premise_a_storeless_merchant_resolves_no_current_store(): void
    {
        $merchant = $this->makeMerchant(['current_store' => null]);

        $this->actingAs($merchant);

        $this->assertNull(
            getCurrentStore(),
            'premise: a merchant with no store must resolve a NULL current store, or the write '
            . 'below cannot reach the platform row and this test proves nothing.'
        );
    }

    /**
     * THE DEFECT, DRIVEN THROUGH THE REAL ROUTE WITH A REAL MERCHANT PRINCIPAL.
     *
     * The review asked for exactly this, because the test that claimed to cover it planted rows
     * with `Setting::create()` and never drove a merchant-reachable writer — so it would have
     * stayed green against this.
     */
    #[Test]
    public function test_a_storeless_merchant_cannot_overwrite_the_official_sites_published_text(): void
    {
        $operatorId = $this->operatorPublishes('The headline the owner published');

        $merchant = $this->makeMerchant(['current_store' => null]);

        $this->actingAs($merchant)->post('/cookie-setting', [
            self::EN => json_encode([
                'home.hero.title' => 'TEXT CHOSEN BY A MERCHANT',
                'legal.terms.text' => '## Terms imposed by a third party',
            ]),
        ]);

        $row = Setting::withoutGlobalScopes()
            ->where('name', self::EN)
            ->whereNull('store_id')
            ->where('created_by', (string) $operatorId)
            ->first();

        $this->assertNotNull($row, 'premise: the operator\'s row must still exist.');

        $this->assertStringNotContainsString(
            'TEXT CHOSEN BY A MERCHANT',
            (string) $row->value,
            'A merchant replaced the official website\'s published text. `CookieSettings` writes '
            . 'one row per POSTED KEY at `store_id => getCurrentStore()`, which is NULL for a '
            . 'storeless merchant; `where(store_id => null)` is `whereNull`, so it matched the '
            . 'platform row; and `SettingOwnership::keep()` restored the operator\'s `created_by` '
            . 'on the way through, so the hijacked row still satisfies the reader.'
        );

        $this->assertStringNotContainsString(
            'Terms imposed by a third party',
            (string) $row->value,
            'A merchant replaced the company\'s LEGAL TEXT.'
        );

        $this->assertStringContainsString(
            'The headline the owner published',
            (string) $row->value,
            'the operator\'s own text must survive untouched.'
        );
    }

    /**
     * THE SECOND HOST WRITER, because fixing one handler would leave the other open and the fix
     * is meant to be in the hook rather than in a handler.
     */
    #[Test]
    public function test_the_seo_settings_writer_cannot_reach_it_either(): void
    {
        $operatorId = $this->operatorPublishes('Owner headline, second route');

        $merchant = $this->makeMerchant(['current_store' => null]);

        $this->actingAs($merchant)->post('/seo-setting', [
            self::EN => json_encode(['home.hero.title' => 'SECOND ROUTE PAYLOAD']),
        ]);

        $row = Setting::withoutGlobalScopes()
            ->where('name', self::EN)->whereNull('store_id')
            ->where('created_by', (string) $operatorId)->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString(
            'SECOND ROUTE PAYLOAD',
            (string) $row->value,
            'The guard covers one handler but not the other, which is why it belongs in the '
            . 'model\'s saving hook rather than in a controller.'
        );
    }

    /**
     * THE CONTROL. The operator must still be able to publish, or the guard is a denial of
     * service on the owner's own screen and the assertions above would pass against a feature
     * that no longer works for anybody.
     */
    #[Test]
    public function test_control_the_operator_can_still_publish(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator);

        PlatformContentStore::put('en', ['home.hero.title' => 'Published by the operator']);

        /*
         * Asserted on the STORED ROW rather than through the reader. The reader's own behaviour
         * is covered by the editor's test suite; what this control has to show is narrower and
         * more direct — that the guard lets the operator's write through at all.
         */
        $row = Setting::withoutGlobalScopes()
            ->where('name', self::EN)->whereNull('store_id')->first();

        $this->assertNotNull(
            $row,
            'The operator can no longer publish their own site. The guard must refuse everybody '
            . 'EXCEPT the operator, not everybody.'
        );

        $this->assertStringContainsString(
            'Published by the operator',
            (string) $row->value,
            'the operator\'s write was accepted but did not store their text.'
        );
    }

    /**
     * AND THE GUARD MUST NOT BREAK ORDINARY SETTINGS. A merchant writing their own, unrelated
     * setting through the same route must still succeed — otherwise the fix is a far wider
     * behaviour change than the defect it closes.
     */
    #[Test]
    public function test_control_a_merchant_can_still_write_an_ordinary_setting(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant)->post('/cookie-setting', [
            'cookie_text' => 'An ordinary merchant setting',
        ]);

        $this->assertNotNull(
            Setting::withoutGlobalScopes()->where('name', 'cookie_text')->first(),
            'The guard refused an unrelated setting. It must be scoped to the platform content '
            . 'names only.'
        );
    }
}
