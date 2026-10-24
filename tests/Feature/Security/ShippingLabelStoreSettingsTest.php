<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-11 -- A SHIPPING LABEL PRINTED ANOTHER STORE'S ADDRESS.
 * ==============================================================
 *
 * OrderController::shippinglabel() read the "FROM" address through `Utility::Setting()` with no argument. For a signed-in user that reads
 * `settings` for (the user's tenant, the user's CURRENT store); when that store has no rows of its own it FALLS BACK to
 * `Setting::where('created_by', 1)->get()` -- every setting row of user id 1, whatever store it belongs to, last row winning. So a label for
 * merchant A's own order printed the address of whichever store user 1 configured last (MEASURED in the P1-SEC-03 round-2 review: store B's
 * address, city, state, zip and country at HTTP 200, twice; the transcript was lost, so this is that measurement re-established).
 *
 * (The second shape the ledger suspected - a merchant with two stores printing a label for the store they are NOT in - cannot occur:
 * OrderDocumentAccessGuard refuses an order outside the merchant's current store with a 403, so the only order reaching the handler is the
 * current store's.)
 *
 * The label now prints the settings of THE ORDER'S store and nothing else's: a store with none prints an empty FROM block.
 */
class ShippingLabelStoreSettingsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function setting(int $storeId, int $createdBy, string $name, string $value): void
    {
        DB::table('settings')->insert(['name' => $name, 'value' => $value, 'store_id' => $storeId, 'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now()]);
        // `Setting` is Cachable, so a raw write leaves an earlier Eloquent read serving the pre-write
        // answer for the rest of the test. No false reading was measured here -- the reads all happen
        // after the inserts -- but this is the project's recorded false-reading mechanism and the
        // sibling file (StoredFileUrlResolutionTest) already flushes, so the two agree rather than
        // leaving the difference for someone to discover.
        (new \App\Models\Setting)->flushCache();
    }

    private function address(int $storeId, int $createdBy, string $tag): void
    {
        foreach (['store_address' => "{$tag}-STREET", 'store_city' => "{$tag}-CITY", 'store_state' => "{$tag}-STATE", 'store_zipcode' => "{$tag}-ZIP", 'store_country' => "{$tag}-COUNTRY"] as $name => $value) {
            $this->setting($storeId, $createdBy, $name, $value);
        }
    }

    private function label($actor, $order)
    {
        Cache::forget('admin_details');

        return $this->actingAs($actor->fresh())->get('/shippinglabel/pdf/' . Crypt::encrypt($order->id));
    }

    private function receipt($actor, $order)
    {
        Cache::forget('admin_details');

        return $this->actingAs($actor->fresh())->get('/order-receipt/' . Crypt::encrypt($order->id));
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: \App\Models\Order} */
    private function storeReadingRightToLeft(string $tag, string $rtl, string $language): array
    {
        [$merchant, $store] = $this->makeTenant();
        // 'Show Order' is OrderDocumentAccessGuard::PERMISSION, which is what both documents require --
        // the same permission the address tests below grant. Granting 'Shipping Label' instead made every
        // assertion in this section fail on a 403, i.e. for a precondition rather than for the defect.
        $this->grantPermission($merchant, 'Show Order');
        $this->setting($store->id, $merchant->id, 'SITE_RTL', $rtl);
        $this->setting($store->id, $merchant->id, 'invoice_logo', "uploads/{$store->id}/{$tag}-LOGO.png");
        \Illuminate\Support\Facades\DB::table('stores')->where('id', $store->id)->update(['default_language' => $language]);
        $this->address($store->id, $merchant->id, $tag);

        return [$merchant, $store, $this->makeOrder($store)];
    }

    private function direction(string $body): ?string
    {
        return preg_match('/<html[^>]*\bdir="([^"]*)"/', $body, $m) === 1 ? $m[1] : null;
    }

    private function language(string $body): ?string
    {
        return preg_match('/<html[^>]*\blang="([^"]*)"/', $body, $m) === 1 ? $m[1] : null;
    }

    // =================================================================================================
    //  P1-SEC-11 criterion 4 -- "checked in Arabic RTL and English LTR on every order document"
    // =================================================================================================

    /**
     * WHAT WAS WRONG, and it was not a styling preference. Both host order documents opened with a
     * HARDCODED `<html lang="en">` and NO `dir` attribute -- while the shipping label's own stylesheet
     * ends with a block of `html[dir="rtl"]` rules (right-aligned table cells, a flipped `.text-right`,
     * a moved QR block). That selector could never match, so its author's right-to-left support was dead
     * code and an Arabic store's label was laid out left to right with Arabic text in it.
     *
     * DRIVEN THROUGH THE REAL ROUTES, in both directions, on both documents.
     */
    public function test_an_rtl_stores_documents_are_laid_out_right_to_left(): void
    {
        [$merchant, , $order] = $this->storeReadingRightToLeft('RTLSTORE', 'on', 'ar');

        foreach (['label' => $this->label($merchant, $order), 'receipt' => $this->receipt($merchant, $order)] as $what => $response) {
            $this->assertNotSame(404, $response->getStatusCode(), "the {$what} route must exist");
            $this->assertSame(200, $response->getStatusCode(), "the {$what} did not render: " . substr((string) $response->getContent(), 0, 200));

            $body = (string) $response->getContent();

            $this->assertSame('rtl', $this->direction($body), "the {$what} is still laid out left to right for an RTL store");
            $this->assertSame('ar', $this->language($body), "the {$what} still declares a hardcoded language");
        }
    }

    public function test_an_ltr_stores_documents_are_laid_out_left_to_right(): void
    {
        [$merchant, , $order] = $this->storeReadingRightToLeft('LTRSTORE', 'off', 'en');

        foreach (['label' => $this->label($merchant, $order), 'receipt' => $this->receipt($merchant, $order)] as $what => $response) {
            $body = (string) $response->getContent();

            $this->assertSame(200, $response->getStatusCode(), "the {$what} did not render");
            $this->assertSame('ltr', $this->direction($body),
                "the {$what} emits no direction at all -- an absent `dir` is the defect, because the RTL rules "
                . 'in the label\'s stylesheet key on the attribute being present');
            $this->assertSame('en', $this->language($body));
        }
    }

    /**
     * AND THE LABEL'S OWN RTL RULES CAN NOW MATCH, which is the point. Asserting the attribute alone would
     * pass against a document whose stylesheet had no right-to-left rules in it at all.
     */
    public function test_the_labels_right_to_left_rules_and_the_attribute_are_in_the_same_response(): void
    {
        [$merchant, , $order] = $this->storeReadingRightToLeft('BOTHSTORE', 'on', 'ar');

        $body = (string) $this->label($merchant, $order)->getContent();

        $this->assertStringContainsString('html[dir="rtl"]', $body,
            'Premise: the label still carries right-to-left rules; without them the attribute proves nothing.');
        $this->assertSame('rtl', $this->direction($body));
    }

    /**
     * AND SO CAN THE RECEIPT'S, which is a separate claim and was a separate defect.
     *
     * The first cut of this change gave the receipt `dir` and the receipt had NO rules keyed on one --
     * found by the read-only review. Declaring a direction a template has no rules for leaves a
     * HALF-FLIPPED document: base text direction reversed while every authored float and text-align
     * stays left-oriented. That is worse than the left-to-right-only layout it replaced.
     *
     * So the generalisation is asserted over BOTH documents rather than for the label alone, which is
     * the form that would have caught it: a template that emits `dir` must carry rules that `dir` can
     * select. Each of the receipt's three rules mirrors one authored LTR rule in its own stylesheet.
     */
    public function test_every_order_document_that_declares_a_direction_carries_rules_keyed_on_it(): void
    {
        [$merchant, , $order] = $this->storeReadingRightToLeft('RULESTORE', 'on', 'ar');

        /*
         * THE MIRROR COUNT IS NAMED PER DOCUMENT, and that is a repair rather than a weakening.
         *
         * The first cut asserted only that `html[dir="rtl"]` appeared somewhere in the body, and a
         * removal check recorded it SURVIVING: deleting ONE of the receipt's three rules left the other
         * two carrying the string. A `>= 2` threshold survived the same mutant for the same reason. The
         * project's own census idiom is the one that bites -- state the number, and say to look at the
         * document rather than edit the number -- so that is what this does.
         *
         * The counts differ legitimately: the label mirrors with two broad selectors (a table-cell rule
         * and a `.text-right` flip) where the receipt needs one per authored float.
         */
        $expectedMirrors = ['label' => 2, 'receipt' => 3];

        $documents = [
            'label' => $this->label($merchant, $order),
            'receipt' => $this->receipt($merchant, $order),
        ];

        foreach ($documents as $what => $response) {
            $body = (string) $response->getContent();

            $this->assertSame('rtl', $this->direction($body), "Premise: the {$what} declares a direction.");

            $this->assertGreaterThan(0,
                preg_match_all('/\\b(?:float|text-align)\\s*:\\s*(?:left|right)\\b/i', $body),
                "Premise: the {$what} authors at least one direction-sensitive declaration, so mirroring "
                . 'it is a real requirement rather than a vacuous one.');

            $mirrored = preg_match_all(
                '/html\\[dir="rtl"\\][^{]*\\{[^}]*\\b(?:float|text-align)\\s*:\\s*(?:left|right)\\b/is',
                $body
            );

            $this->assertSame($expectedMirrors[$what], $mirrored,
                "the {$what} declares dir=\"rtl\" and mirrors {$mirrored} direction-sensitive declarations "
                . "where it carried {$expectedMirrors[$what]}. FEWER means a float or a text alignment now "
                . 'stays left-oriented while the text direction reverses -- a half-flipped document, which '
                . 'is worse than the left-to-right one it replaced. MORE means a rule was added: look at '
                . 'the document in both directions rather than changing this number.');
        }

        $this->assertSame(array_keys($expectedMirrors), array_keys($documents),
            'both host order documents are covered. A third would need its own right-to-left rules '
            . 'before it may emit the attribute.');
    }

    /**
     * THE LOGO IS THE ORDER'S STORE'S -- criterion 1's remainder, found while doing criterion 4.
     *
     * Both views fetched `GetValueByName('invoice_logo')` with NO store id, which resolves
     * `getCurrentStore()` -- the VIEWER's store. Criterion 1 fixed the five address names and not this, so
     * the platform operator printing a merchant's label or receipt put the OPERATOR's logo on it.
     */
    public function test_the_documents_print_the_orders_stores_logo_and_not_the_viewers(): void
    {
        /*
         * THE OPERATOR IS CREATED FIRST, deliberately: `Utility::Setting()` and the `admin_details` cache
         * read the platform user as id 1 (P1-SEC-11's own subject), so a fixture that makes the MERCHANT
         * id 1 puts the two identities in the wrong order and the label 500s -- a precondition failure
         * wearing the defect's clothes. The passing address test above makes the operator first for the
         * same reason.
         */
        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator);
        \App\Models\User::where('id', $operator->id)->update(['current_store' => $operatorStore->id]);
        $this->setting($operatorStore->id, $operator->id, 'invoice_logo', 'uploads/' . $operatorStore->id . '/OPERATOR-LOGO.png');

        [, , $order] = $this->storeReadingRightToLeft('OWNLOGO', 'off', 'en');

        foreach (['label' => $this->label($operator, $order), 'receipt' => $this->receipt($operator, $order)] as $what => $response) {
            $body = (string) $response->getContent();

            $this->assertSame(200, $response->getStatusCode(), "the {$what} did not render for the operator");
            $this->assertStringContainsString('OWNLOGO-LOGO.png', $body,
                "the {$what} did not print the ORDER's store's logo");
            $this->assertStringNotContainsString('OPERATOR-LOGO.png', $body,
                "the {$what} printed the VIEWER's logo on another store's document");
        }
    }

    /**
     * THE DIRECTION IS THE ORDER'S STORE'S TOO, not the viewer's -- the same rule as the logo, and the gap
     * a removal check found. Every test above has the ORDER'S OWN MERCHANT as the viewer, so viewer store
     * and order store are the same row and `isRtl($storeId)` could drop its argument entirely without any
     * of them noticing (MEASURED: mutant M6 survived nine passing tests). Here the two differ: the
     * operator's own store reads left-to-right in English and the order's store reads right-to-left in
     * Arabic, so each attribute can only come from one of them.
     */
    public function test_the_direction_and_language_come_from_the_orders_store_and_not_the_viewers(): void
    {
        $operator = $this->makePlatformAdmin();                       // created first -- see the logo test's note
        $operatorStore = $this->makeStore($operator);
        \App\Models\User::where('id', $operator->id)->update(['current_store' => $operatorStore->id]);
        $this->setting($operatorStore->id, $operator->id, 'SITE_RTL', 'off');
        DB::table('stores')->where('id', $operatorStore->id)->update(['default_language' => 'en']);

        [, , $order] = $this->storeReadingRightToLeft('VIEWERDIFFERS', 'on', 'ar');

        foreach (['label' => $this->label($operator, $order), 'receipt' => $this->receipt($operator, $order)] as $what => $response) {
            $body = (string) $response->getContent();

            $this->assertSame(200, $response->getStatusCode(), "the {$what} did not render for the operator");
            $this->assertSame('rtl', $this->direction($body),
                "the {$what} took its direction from the VIEWER's store, so an Arabic store's document printed "
                . 'left-to-right whenever anyone but its own merchant printed it');
            $this->assertSame('ar', $this->language($body), "the {$what} took its language from the VIEWER's store");
        }
    }

    /**
     * AND THE REAL DEFECT WAS ONE LAYER DOWN -- `GetValueByName($name, $storeId)` IS NOT STORE-SAFE.
     *
     * Found by the read-only tenancy review of the first cut of this fix, and MEASURED before being
     * believed. Passing the order's store id to `Utility::GetValueByName()` is not enough:
     *
     *   * its PRIMARY read is correctly `where('store_id', $storeId)` (Utility.php:214-220), but
     *   * on a MISS it falls through to `Utility::Setting($store_id)` (:228), and
     *   * `Utility::Setting()` IGNORES its own `$store_id` argument whenever `Auth::check()` is true
     *     (:139-141) -- it reads `getCurrentStore()`, THE VIEWER'S STORE.
     *
     * So the fix held only for a store that HAS a row of that name. And `invoice_logo` is NEVER
     * SEEDED: `defaultSetting()` seeds SITE_RTL and not it, and the only writers are the merchant's
     * own App Settings upload and the admin API -- so "no row" is the DEFAULT STATE of every
     * merchant store that never uploaded a logo, i.e. the common case rather than an edge one.
     *
     * MEASURED on that state, operator viewing a merchant's receipt: `OPERATOR-LOGO.png` present,
     * and `dir="rtl"` taken from the OPERATOR'S flag while the order's store had no row at all.
     * The tests above could not see it because their own fixture inserts both rows for the order's
     * store, which is the fifth recorded way a test passes for the wrong reason: the fixture steps
     * around the surviving case.
     *
     * Both names are therefore read from the `settings` table DIRECTLY now, with the documented
     * defaults -- the same shape `shippinglabel()` already uses for the five address names.
     */
    public function test_a_store_with_no_logo_row_does_not_fall_back_to_the_viewers_logo(): void
    {
        [$operator, , $order] = $this->operatorViewingAStoreWithNoRowsOfItsOwn();

        foreach (['label' => $this->label($operator, $order), 'receipt' => $this->receipt($operator, $order)] as $what => $response) {
            $body = (string) $response->getContent();

            $this->assertSame(200, $response->getStatusCode(), "the {$what} did not render");
            $this->assertStringNotContainsString('OPERATOR-LOGO.png', $body,
                "the {$what} printed the VIEWER's logo on a store whose own invoice_logo row is absent -- "
                . 'GetValueByName() falls through to Utility::Setting(), which ignores its store id');
        }
    }

    public function test_a_store_with_no_direction_row_does_not_inherit_the_viewers_direction(): void
    {
        [$operator, , $order] = $this->operatorViewingAStoreWithNoRowsOfItsOwn();

        foreach (['label' => $this->label($operator, $order), 'receipt' => $this->receipt($operator, $order)] as $what => $response) {
            $body = (string) $response->getContent();

            $this->assertSame(200, $response->getStatusCode(), "the {$what} did not render");
            $this->assertSame('ltr', $this->direction($body),
                "the {$what} took the VIEWER's SITE_RTL for a store that has no such row. An absent row means "
                . "the documented default 'off', not whatever the person printing it happens to have set");
        }
    }

    /**
     * The operator has both rows and reads right-to-left; the ORDER'S store has neither row.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Store, 2: \App\Models\Order}
     */
    private function operatorViewingAStoreWithNoRowsOfItsOwn(): array
    {
        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator);
        \App\Models\User::where('id', $operator->id)->update(['current_store' => $operatorStore->id]);
        (new \App\Models\User)->flushCache();
        $this->setting($operatorStore->id, $operator->id, 'invoice_logo', 'uploads/' . $operatorStore->id . '/OPERATOR-LOGO.png');
        $this->setting($operatorStore->id, $operator->id, 'SITE_RTL', 'on');
        $this->setting($operatorStore->id, $operator->id, 'storage_setting', 'local');

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');

        // makeTenant() goes through the store-creation path, which seeds SITE_RTL. Remove both names so
        // the state under test is the one that actually ships: a store with no row of either.
        DB::table('settings')->where('store_id', $store->id)->whereIn('name', ['invoice_logo', 'SITE_RTL'])->delete();
        (new \App\Models\Setting)->flushCache();

        $this->assertSame([], DB::table('settings')->where('store_id', $store->id)
            ->whereIn('name', ['invoice_logo', 'SITE_RTL'])->pluck('value')->all(),
            'Premise: the order\'s store has neither row, which is what makes the fallback the thing under test.');

        return [$operator, $store, $this->makeOrder($store)];
    }

    /**
     * AN EXPLICITLY EMPTY `invoice_logo` ROW is the state the fallback exists for, and the one the test
     * above does NOT reach: `GetValueByName()` falls through to `Utility::Setting()`'s defaults when the row
     * is ABSENT (`invoice_logo => storage/uploads/logo/logo.png`, Utility.php:53), so a store with no row
     * never sees an empty value. A row that EXISTS and is empty is `isset()`, so it is returned as '' --
     * and that is what the settings form writes when no file is chosen. MEASURED: mutant M9, the fallback
     * removed, survived every other test here.
     */
    public function test_a_store_whose_invoice_logo_row_is_empty_still_renders_an_image(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        $this->setting($store->id, $merchant->id, 'invoice_logo', '');
        $order = $this->makeOrder($store);

        $this->assertSame([''], DB::table('settings')->where('store_id', $store->id)->where('name', 'invoice_logo')->pluck('value')->all(),
            'Premise: the row exists and is empty, which is what makes GetValueByName() return \'\' instead of the default.');

        foreach (['label' => $this->label($merchant, $order), 'receipt' => $this->receipt($merchant, $order)] as $what => $response) {
            $body = (string) $response->getContent();

            $this->assertSame(200, $response->getStatusCode(), "the {$what} did not render");
            $this->assertStringNotContainsString('src=""', $body,
                "the {$what} rendered an empty image source for a store whose logo row is empty");
        }
    }

    /**
     * AND A STORE WITH NO LOGO OF ITS OWN STILL GETS A `src`, which is where the SECOND defect lived.
     *
     * Both templates fell back to `$profile . '/logo.png'` with `$profile` DEFINED NOWHERE -- not by the
     * controller, not by a view composer, not shared; grepped -- so an empty `invoice_logo` turned both
     * documents into a 500 with "Undefined variable $profile". The FIRST defect is what kept it latent:
     * reading the viewer's store almost always found a row. Fixing the store id alone would therefore have
     * TRADED a wrong logo for a dead document, so the fallback is resolved in the service and the dead
     * expression is gone from the templates.
     */
    public function test_a_store_with_no_invoice_logo_still_renders_a_document_with_an_image(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        $this->address($store->id, $merchant->id, 'NOLOGO');   // settings rows, but no `invoice_logo` among them
        $order = $this->makeOrder($store);

        $this->assertSame([], DB::table('settings')->where('store_id', $store->id)->where('name', 'invoice_logo')->get()->all(),
            'Premise: the store under test has no invoice_logo row.');

        foreach (['label' => $this->label($merchant, $order), 'receipt' => $this->receipt($merchant, $order)] as $what => $response) {
            $body = (string) $response->getContent();

            $this->assertSame(200, $response->getStatusCode(),
                "the {$what} did not render for a store with no logo: " . substr(strip_tags($body), 0, 300));
            $this->assertStringNotContainsString('Undefined variable', $body);
            $this->assertStringNotContainsString('src=""', $body,
                "the {$what} rendered an empty image source instead of the platform default");
        }
    }

    /**
     * R5 -- A STORE ID OF 0 MUST READ NO ROW, which a removal check found untested.
     *
     * `$orderStore` is 0 for an order whose `store_id` is NULL, and the read-only review established
     * that such an order is reachable by THE OPERATOR ALONE (the merchant and shopper limbs of
     * OrderDocumentAccessGuard both fail closed on a null store). `store_id = 0` is a value rows really
     * carry -- the P1-PLT-08 diagnostic counts `delivery_boys` rows in exactly that state -- so without
     * the strict parse the document would be answered with store 0's settings instead of refusing.
     *
     * ASSERTED ON THE HALF SQLITE CAN SHOW. A `(int)` cast or a missing guard makes
     * `where('store_id', 0)` match the row below on every engine. The OTHER half -- MySQL coercing a
     * junk-suffix id like '5abc' to row 5 -- SQLite's affinity hides, and it is recorded in the
     * service's own comment rather than claimed here.
     */
    public function test_a_document_for_a_store_id_of_zero_reads_no_settings_row(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->setting(0, $operator->id, 'SITE_RTL', 'on');
        $this->setting(0, $operator->id, 'invoice_logo', 'uploads/0/ZEROSTORE-LOGO.png');

        $this->assertFalse(\App\Services\DocumentDirection::isRtl(0),
            'a store id of 0 read store 0\'s SITE_RTL row instead of refusing');
        $this->assertStringNotContainsString('ZEROSTORE-LOGO', \App\Services\DocumentDirection::invoiceLogoFor(0),
            'a store id of 0 read store 0\'s invoice_logo row instead of refusing');

        foreach (['', null, 'abc', -1] as $unusable) {
            $this->assertFalse(\App\Services\DocumentDirection::isRtl($unusable),
                'an unusable store id (' . var_export($unusable, true) . ') resolved a direction');
        }
    }

    /**
     * R6 -- THE LANGUAGE FALLBACK IS THE PLATFORM'S, NOT THE READER'S, which a removal check found
     * untested because the two are equal in a default test run.
     *
     * `stores.default_language` is nullable with no default, so a store that never set one needs a
     * fallback. `app()->getLocale()` is whatever the VIEWER'S request resolved through
     * `themelanguage`/`SetLocate` -- so the document would declare the language of whoever printed it,
     * which is the same viewer-derived answer the logo and the direction were just fixed to stop
     * giving. Driven with the runtime locale set away from the configured one, so only one of the two
     * can satisfy it.
     */
    public function test_a_store_with_no_language_declares_the_platform_default_not_the_viewers_locale(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        DB::table('stores')->where('id', $store->id)->update(['default_language' => null]);
        $order = $this->makeOrder($store);

        config(['app.locale' => 'en']);
        app()->setLocale('fr');                      // the VIEWER's language, and nothing to do with the store

        $this->assertSame('en', \App\Services\DocumentDirection::localeFor($store->id),
            "a store with no language of its own declared the VIEWER's locale");

        $body = (string) $this->label($merchant, $order)->getContent();

        $this->assertNotSame('fr', $this->language($body),
            "the label declared the VIEWER's language for a store that has none of its own");
    }

    /**
     * THE CENSUS, and the residual it records. Every HOST template that opens its own `<html>` for an
     * order document must take both attributes from the document's own data; the vendored ones cannot be
     * edited and are named instead, so the set is stated rather than implied.
     */
    public function test_no_host_order_document_hardcodes_its_language_or_omits_its_direction(): void
    {
        $hostDocuments = glob(resource_path('views/order/*.blade.php')) ?: [];

        $this->assertNotEmpty($hostDocuments, 'the order views are not where this expects them');

        $checked = 0;

        foreach ($hostDocuments as $file) {
            // Blade comments stripped FIRST, because the note each of these templates now carries quotes
            // the old `<html lang="en">` in order to explain it -- and the first cut of this census matched
            // that prose instead of the tag, so it failed against correct code. The recorded trap, in its
            // usual direction this time.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file)) ?? '';

            // Matched to the END OF THE LINE, not to the first `>`: the attributes are Blade expressions and
            // `app()->getLocale()` contains a `>`, so a `[^>]*>` pattern stops inside the expression and
            // reports the tag as carrying no `dir` -- which it did, against correct code.
            if (! preg_match('/^.*<html\b.*$/m', $source, $m)) {
                continue;                       // extends a layout, which carries the attributes
            }

            $name = basename($file);

            $this->assertStringNotContainsString('lang="en"', $m[0],
                "{$name} hardcodes lang=\"en\" on a document that any store may print");
            $this->assertStringContainsString('dir=', $m[0],
                "{$name} opens an order document with no direction, so a right-to-left store's copy is laid "
                . 'out left to right');
            $checked++;
        }

        $this->assertSame(2, $checked,
            'two host order documents open their own <html> (shippinglabel and receipt). A different number '
            . 'means one was added or removed -- look at it rather than changing this number.');
    }

    public function test_a_store_without_settings_does_not_print_the_platform_users_last_configured_address(): void
    {
        $operator = $this->makePlatformAdmin();                       // user id 1: the `created_by = 1` fallback
        $this->assertSame(1, (int) $operator->id, 'Premise: the platform user is id 1, the literal the fallback reads.');
        $operatorStore = $this->makeStore($operator);
        $this->address($operatorStore->id, 1, 'OTHERSTORE');

        [$merchant, $store] = $this->makeTenant();                   // no settings rows of its own
        $this->grantPermission($merchant, 'Show Order');
        $order = $this->makeOrder($store);

        $response = $this->label($merchant, $order);

        $response->assertOk();
        $this->assertStringNotContainsString('OTHERSTORE', $response->getContent(), "A label printed another store's address.");
    }

    public function test_the_label_prints_the_address_of_the_orders_own_store(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        $this->address($store->id, $merchant->id, 'OWNSTORE');
        $order = $this->makeOrder($store);

        $response = $this->label($merchant, $order);

        $response->assertOk();
        foreach (['STREET', 'CITY', 'STATE', 'ZIP', 'COUNTRY'] as $part) {
            $this->assertStringContainsString("OWNSTORE-{$part}", $response->getContent(), "Premise: the order's own store address is printed ({$part}).");
        }
    }

    /**
     * The handler is correct by construction, not only by virtue of the guard in front of it: called DIRECTLY (as a future route or a changed
     * guard would), a merchant whose current store is A and an order of store B still gets store B's address.
     */
    public function test_the_handler_itself_reads_the_orders_store_not_the_viewers(): void
    {
        [$merchant, $current] = $this->makeTenant();
        $other = $this->makeStore($merchant);
        $this->address($current->id, $merchant->id, 'CURRENTSTORE');
        $this->address($other->id, $merchant->id, 'ORDERSTORE');
        $this->setting($current->id, $merchant->id, 'invoice_logo', 'uploads/' . $current->id . '/CURRENTSTORE-LOGO.png');
        $this->setting($current->id, $merchant->id, 'SITE_RTL', 'on');
        DB::table('settings')->where('store_id', $other->id)->whereIn('name', ['invoice_logo', 'SITE_RTL'])->delete();
        (new \App\Models\Setting)->flushCache();
        $order = $this->makeOrder($other);
        $this->actingAs($merchant->fresh());
        Cache::forget('admin_details');

        $view = app(\App\Http\Controllers\OrderController::class)->shippinglabel(request(), Crypt::encrypt($order->id));

        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view);
        $this->assertSame('ORDERSTORE-STREET', $view->getData()['settings']['store_address']);
        $this->assertSame('ORDERSTORE-COUNTRY', $view->getData()['settings']['store_country']);

        /*
         * AND THE SAME, BY CONSTRUCTION, FOR THE TWO NAMES CRITERION 4 ADDED -- with the rows present on
         * the VIEWER'S store and ABSENT on the order's, which is the arrangement that separates the
         * primary read from the fallback. Reading them through `GetValueByName($name, $storeId)` passes
         * the first two assertions in this method and fails these, because that helper's miss path
         * resolves the viewer's store however correct its argument is.
         */
        $this->assertSame([], DB::table('settings')->where('store_id', $other->id)
            ->whereIn('name', ['invoice_logo', 'SITE_RTL'])->pluck('value')->all(),
            'Premise: the ORDER\'s store has neither row.');

        $this->assertStringNotContainsString('CURRENTSTORE-LOGO', (string) $view->getData()['invoice_logo'],
            'the handler resolved the VIEWER\'s logo for a store that has no row of its own');
        $this->assertFalse($view->getData()['documentRtl'],
            'the handler took the VIEWER\'s SITE_RTL for a store that has no row of its own');
    }
}
