<?php

namespace Tests\Feature\Merchant;

use App\Models\Shipping;
use App\Models\Store;
use App\Models\TaxMethod;
use App\Models\TaxOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F-validation-errors-lose-the-modal, PART 3 -- THE COMMERCE SETTINGS GROUP (8 refusal sites).
 * =================================================================================================
 *
 * `ModalFormRefusalFeedbackTest` landed the one-expression repair on `CategoryController`;
 * `ValidationRefusalNamesEveryFieldTest` landed it on seven more controllers (18 sites). This file
 * is the shipping/tax/currency settings group, and the same single expression:
 *
 *     -  return redirect()->back()->with('error', $messages->first());
 *     +  return redirect()->back()->with('error', implode(' ', $messages->all()));
 *
 * applied to ShippingController (2 sites), ShippingZoneController (1), TaxMethodController (2),
 * TaxOptionController (1) and CurrencyController (2). Same flash key, same 302, same session
 * shape, no new `fails()` guard, no rule touched.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT WAS MEASURED, NOT ASSUMED -- and the two census corrections it produced.
 *
 * Every one of the eight rule sets was run through `Validator::make` and its bag COUNTED
 * (2026-11-04), rather than its rule keys counted:
 *
 *     ShippingController::store / ::update        name + description            -> bag 2
 *     ShippingZoneController::store               zone_name + shipping_method   -> bag 2
 *     TaxMethodController::store / ::update       name + tax_rate               -> bag 2
 *     TaxOptionController::taxSettings            price_type/shop/checkout      -> bag 3
 *     CurrencyController::store / ::update        name + code + symbol          -> bag 3
 *
 * ALL EIGHT ARE OBSERVABLE: every one can hold more than one message, so the join is a
 * user-visible repair at every site and is proved HERE BY DRIVING IT. There is no INERT site in
 * this group, so the byte-for-byte negative control that
 * `ValidationRefusalNamesEveryFieldTest::test_a_single_rule_refusal_flashes_exactly_one_message...`
 * carries has no subject here. Its job -- "the join must not pad, reorder or re-separate" -- is
 * instead done by `assertSame()` against `implode(' ', ...)` of the live bag at all eight sites,
 * plus the explicit "no outer padding, sentence count == bag size" assertions in
 * `assertJoinedExactly()`.
 *
 * CENSUS CORRECTION 1 -- SHIPPING ZONE IS 2 MESSAGES, NOT 4. The brief's regex counted four quoted
 * keys, but `country_id` and `state_id` are `'nullable'`, a rule that can never produce a message.
 * MEASURED both ways -- with and without the handler's own `country_id`/`state_id` 0->null merge at
 * `ShippingZoneController.php:97-:102` -- the bag is 2. `test_the_shipping_zone_refusal...` asserts
 * that boundary explicitly, so if a future `required` lands on either field the count moves and
 * this file says so rather than silently passing.
 *
 * CENSUS CORRECTION 2 -- CURRENCY IS NOT A MERCHANT SETTING. It is PLATFORM SUPER ADMIN:
 * `CurrencyController::index()` (`:17`) additionally demands `type == 'super admin'`; the currency
 * permissions are seeded only onto the super-admin role (`Utility.php` `$superadminNewPermission`,
 * absent from `$companyNewPermission`); and `currency` has NO `store_id` -- it is a global
 * reference table. So the two currency sites are driven here as the PLATFORM OPERATOR, which is
 * the principal that really reaches them, and the group is named "commerce settings" rather than
 * "merchant settings".
 *
 * ------------------------------------------------------------------------------------------------
 * THE TRAP THIS CHANGE HAD TO AVOID, pinned by `test_no_owned_controller_gained_a_newly_consulted_validator`.
 * `AppSettingController` builds SIX validators and consults TWO, so giving a dormant one a
 * `fails()` guard would start REFUSING posts that succeed today -- a business-rule change wearing a
 * repair's clothes. MEASURED in these five files: `Validator::make(` and `->fails()` are EQUAL in
 * every one (2/2, 1/1, 2/2, 1/1, 2/2), so all eight sites were already live refusal branches and
 * this change alters only the MESSAGE of a refusal that already happened. Eight valid posts are
 * driven below to prove the refusals did not widen.
 *
 * ------------------------------------------------------------------------------------------------
 * AND THE WIDENING DISCLOSES NOTHING AT ANY OF THE EIGHT. Seven bags name only the caller's own
 * posted form fields. The eighth is the one worth stating: a DUPLICATE currency post now answers
 * all three `unique` collisions instead of one, so it reveals that a row with that name, code or
 * symbol exists. That is not new information -- `first()` already revealed one of the three, the
 * caller supplied the values, and `currency` is a global reference table whose every row the
 * operator's own index lists. `test_the_currency_store_refusal_names_all_three_failing_fields`
 * drives exactly that shape.
 *
 * ------------------------------------------------------------------------------------------------
 * NOTHING FINANCIAL MOVED. `tax_rate`, `priority`, `enable_shipping` and the shipping cost are all
 * written from the request AFTER the refusal branch and are untouched by it; no rate, no rounding
 * and no price is read or written by the expression this change replaces. `TaxOptionController`'s
 * own consistency clamp (`:101-:113`) sits below the validator and is likewise untouched.
 *
 * ------------------------------------------------------------------------------------------------
 * FOUR THINGS FOUND WHILE MEASURING, REPORTED AND NOT REPAIRED HERE -- each is outside a message
 * change, and `test_the_reported_authorization_gaps_are_still_exactly_as_reported` pins them so a
 * later reader finds them as facts rather than as prose:
 *
 *   (a) `CurrencyController::store()`/`::update()` carry NO `type` check, unlike `index()`. A
 *       merchant (`type = 'admin'`) who somehow holds `Create currency` reaches the validator and
 *       can insert a PLATFORM-WIDE currency row. Pre-existing; a permission/type change, not a
 *       message change.
 *   (b) `TaxOptionController::taxSettings` has NO operator limb, unlike the shipping and tax-method
 *       writes, which refuse the operator explicitly and cite the F157 precedent. THE GAP IS
 *       LATENT, NOT LIVE, and the classification stage had this half-wrong until it was driven:
 *       the operator does NOT reach the validator today, because the only gate is
 *       `isAbleTo('Manage Tax-option')` and Laratrust has no super-admin bypass. `Manage
 *       Tax-option` exists as a Permission ROW (`Utility.php` `$arrPermissions`) but is absent
 *       from `$superadminNewPermission`, so no seeded super-admin role holds it. Tick it in the
 *       role editor, though -- which that seeded row makes possible -- and the operator reaches
 *       the validator and a valid post writes `tax_options` for `getCurrentStore()`, i.e. rewrites
 *       whichever merchant's tax settings they are currently viewing. Both halves are driven.
 *   (c) `taxSettings` gates a WRITE on the read-shaped `Manage Tax-option`; no `Edit Tax-option`
 *       is seeded, so there is no finer name to use today.
 *   (d) `routes/customer.php:30` imports `CurrencyController` and routes it nowhere -- a dead use.
 *
 * ------------------------------------------------------------------------------------------------
 * AND THE ORDERING THAT MAKES THE TWO UPDATES REACHABLE AGAINST A FOREIGN ROW. In both
 * `ShippingController::update` and `TaxMethodController::update` the validator runs BEFORE the
 * store predicate (`scopedShipping` / `scopedTaxMethod`), so the refusal answers for an id the
 * caller does not own. That ordering is pre-existing and the widening adds NO disclosure: the bag
 * names only the caller's own two posted form fields, never anything about the foreign row.
 * `test_a_foreign_id_update_still_answers_not_found_and_leaves_the_row_untouched` drives a VALID
 * payload against a foreign id and proves the predicate below still refuses it.
 */
class CommerceSettingsRefusalNamesEveryFieldTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The five files this change owns, with their measured refusal-site count. */
    private const OWNED = [
        'app/Http/Controllers/ShippingController.php' => 2,
        'app/Http/Controllers/ShippingZoneController.php' => 1,
        'app/Http/Controllers/TaxMethodController.php' => 2,
        'app/Http/Controllers/TaxOptionController.php' => 1,
        'app/Http/Controllers/CurrencyController.php' => 2,
    ];

    private User $merchant;

    private Store $store;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchant, $this->store] = $this->commerceTenant();

        /* THE CURRENCY PRINCIPAL -- see census correction 2. `index()` demands `super admin`, so
           the operator is the principal this surface is really for. */
        $this->operator = $this->makePlatformAdmin();
        foreach (['Manage currency', 'Create currency', 'Edit currency'] as $permission) {
            $this->grantPermission(User::find($this->operator->id), $permission);
        }
        $this->operator = User::find($this->operator->id);
    }

    /**
     * A merchant holding every permission the five handlers check, with `current_store` set --
     * because `shippingWriteRefusal()` and `taxWriteRefusal()` both end in `storeMustResolve()`,
     * which answers `No store is selected.` and would be a refusal for the wrong reason.
     *
     * @return array{0: User, 1: Store}
     */
    private function commerceTenant(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        foreach ([
            'Manage Shipping Class', 'Create Shipping Class', 'Edit Shipping Class',
            'Manage Shipping Zone', 'Create Shipping Zone',
            'Manage Tax Method', 'Create Tax Method', 'Edit Tax Method',
            'Manage Tax-option',
        ] as $permission) {
            $this->grantPermission(User::find($merchant->id), $permission);
        }

        return [User::find($merchant->id), $store];
    }

    // =============================================================================================
    //  SHARED MACHINERY
    // =============================================================================================

    /**
     * A controller's source with every comment REMOVED, via `token_get_all()`.
     *
     * The project skill's rule: "strip comment lines from any source-grep ratchet -- a docblock
     * quoting the string a ratchet greps for makes the ratchet satisfiable by prose." These five
     * files are heavily commented, THIS file's own docblock quotes both expressions, and every
     * source assertion below is a COUNT -- so a future docblock inside one of the five could hold a
     * count at its expected value while a real refusal site was deleted. T_COMMENT and
     * T_DOC_COMMENT are dropped; nothing inside a string literal is touched.
     */
    private function codeWithoutComments(string $relative): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(base_path($relative))) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * Drive a refusal and hand back the flashed `error` string, having first proved the premise:
     * a 302 back to the referer which is neither a 404, a 405, a 500, nor the permission branch.
     *
     * The 404/405 check is not ceremony. `Route::resource` gives PUT|PATCH for update, so a POST
     * matches no route -- and a "the flash contains two sentences" assertion would fail for the
     * right reason while a "the row is unchanged" assertion would PASS for entirely the wrong one.
     * `ShippingClassAndTaxMethodTenancyTest` records that costing a whole run.
     *
     * BOTH SPELLINGS of the permission refusal are excluded, case-insensitively:
     * `CurrencyController` says `Permission Denied.` with a capital D while the other four say
     * `Permission denied.`, so a case-sensitive needle -- which is what the sibling file uses --
     * would not have caught a currency permission answer masquerading as a validation one.
     */
    private function refusalFlash(
        User $principal,
        string $route,
        array $payload,
        string $referer,
        string $method = 'post',
        array $routeParams = []
    ): string {
        $response = $this->actingAs($principal)
            ->from($referer)
            ->{$method}(route($route, $routeParams), $payload);

        $this->assertNotSame(404, $response->status(), "{$route} does not exist as driven");
        $this->assertNotSame(405, $response->status(),
            "{$route} rejected the HTTP verb, so the handler never ran");
        $this->assertNotSame(500, $response->status(), "{$route} errored rather than refusing");
        $this->assertSame(302, $response->status(), "{$route} did not redirect back");
        $response->assertRedirect($referer);

        $flash = (string) $response->getSession()->get('error');

        $this->assertNotSame('', $flash,
            "{$route} flashed nothing at all, so this is not the validation branch");
        $this->assertStringNotContainsStringIgnoringCase('permission den', $flash,
            "{$route} answered with the PERMISSION refusal, not the validation one -- the fixture "
            . 'is missing a permission and the test is measuring the wrong return');
        $this->assertStringNotContainsStringIgnoringCase('no store is selected', $flash,
            "{$route} answered storeMustResolve(), not the validator -- the fixture's "
            . 'current_store did not resolve');

        /* AND the response shape is unchanged: no old input, which paragraph 3 of
           ModalFormRefusalFeedbackTest pins as unrecoverable through this architecture. */
        $this->assertSame([], (array) $response->getSession()->get('_old_input', []),
            "{$route} started flashing old input, which no reader can reach here");

        return $flash;
    }

    /**
     * The flash is EXACTLY the live bag joined by one space -- no padding, no reordering, no extra
     * separator -- and it carries one sentence per message. The second half is what stops a repair
     * that "joins" with something that concatenates junk, and it is the same control the inert
     * sites in the sibling file provide there.
     */
    private function assertJoinedExactly(array $data, array $rules, string $flash, string $where): void
    {
        $bag = \Validator::make($data, $rules)->getMessageBag();
        $all = $bag->all();

        $this->assertGreaterThan(1, count($all),
            "{$where} is not OBSERVABLE after all -- its bag holds " . count($all) . ' message(s), '
            . 'so this test proves nothing about the join. Re-measure the rules.');

        $this->assertSame(implode(' ', $all), $flash,
            "{$where} does not flash the whole message bag joined by a single space");
        $this->assertSame($flash, trim($flash), "{$where} gained outer padding");
        $this->assertSame(count($all), substr_count($flash, '.'),
            "{$where} does not carry exactly one sentence per message in the bag");

        /* THE CONTROL ON THE CONTROL: the OLD expression really did discard the rest. If this
           stops holding, the defect being closed is not the defect that was measured. */
        $this->assertNotSame(implode(' ', $all), (string) $bag->first(),
            "{$where}: \$messages->first() now equals the whole bag, so there was nothing to fix");
        $this->assertStringNotContainsString((string) $all[count($all) - 1], (string) $bag->first(),
            "{$where}: the LAST message -- the one most certainly discarded by first() -- is "
            . 'already inside first()');
    }

    /**
     * THE NEGATIVE CONTROL THIS GROUP OTHERWISE LACKS. All eight sites are OBSERVABLE, so none of
     * them supplies the byte-for-byte case that the ten INERT sites in
     * `ValidationRefusalNamesEveryFieldTest` supply there -- and that case is what kills a "repair"
     * that pads, reorders or re-separates. It is reconstructed here by driving a PARTIALLY FILLED
     * form: one rule left failing, so the bag really does hold one message, and the flash must be
     * exactly that message's bytes with no join artefact at either end.
     */
    private function assertFlashedExactlyOneMessage(
        array $data, array $rules, string $flash, string $where
    ): void {
        $all = \Validator::make($data, $rules)->getMessageBag()->all();

        $this->assertCount(1, $all,
            "{$where}: this payload no longer leaves exactly ONE rule failing, so it is not the "
            . 'one-message control any more. Re-measure before trusting it.');

        $this->assertSame($all[0], $flash,
            "{$where} no longer flashes exactly the validator's own message. A join that pads, "
            . 'reorders or re-separates a one-message bag is a user-visible wording change on a '
            . 'settings screen that was not meant to change at all.');
        $this->assertSame($flash, trim($flash),
            "{$where}: the joined single message gained outer padding");
        $this->assertSame(1, substr_count($flash, '.'),
            "{$where}: a one-message refusal now carries more than one sentence");
    }

    private function taxGroupId(Store $store): int
    {
        return (int) \DB::table('taxes')->insertGetId([
            'name' => 'Tax group ' . uniqid(),
            'store_id' => $store->id,
        ]);
    }

    private function shippingClassIn(Store $store, string $name): Shipping
    {
        $row = Shipping::create([
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)),
            'description' => $name . ' description',
            'store_id' => $store->id,
        ]);
        (new Shipping)->flushCache();

        return $row;
    }

    /** `store_id` is not in TaxMethod's `$fillable`, and `tax_id` carries a real foreign key. */
    private function taxMethodIn(Store $store, string $name, string $rate): TaxMethod
    {
        $row = new TaxMethod();
        $row->name = $name;
        $row->tax_rate = $rate;
        $row->tax_id = $this->taxGroupId($store);
        $row->priority = 1;
        $row->enable_shipping = 0;
        $row->store_id = $store->id;
        $row->save();
        (new TaxMethod)->flushCache();

        return $row;
    }

    // =============================================================================================
    //  1. THE OBSERVABLE EIGHT -- driven, and every failing field named
    // =============================================================================================

    public function test_the_shipping_class_store_refusal_names_both_failing_fields(): void
    {
        $flash = $this->refusalFlash($this->merchant, 'shipping.store', [], route('shipping.index'));

        $this->assertJoinedExactly([], ['name' => 'required', 'description' => 'required'],
            $flash, 'ShippingController::store');

        foreach (['name', 'description'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the shipping class store refusal does not name '{$field}'");
        }

        /* AND NOTHING WAS WRITTEN -- read raw, because Shipping is Cachable. */
        $this->assertSame(0, (int) \DB::table('shippings')->where('store_id', $this->store->id)->count(),
            'the refused shipping class was created anyway');
    }

    public function test_the_shipping_class_update_refusal_names_both_failing_fields(): void
    {
        $mine = $this->shippingClassIn($this->store, 'Mine');

        $flash = $this->refusalFlash($this->merchant, 'shipping.update',
            [], route('shipping.index'), 'put', [$mine->id]);

        $this->assertJoinedExactly([], ['name' => 'required', 'description' => 'required'],
            $flash, 'ShippingController::update');

        $this->assertStringContainsString('description', $flash,
            'the shipping class update refusal still discards the description message');

        (new Shipping)->flushCache();
        $this->assertSame('Mine', (string) \DB::table('shippings')->where('id', $mine->id)->value('name'),
            'the refused update renamed the shipping class anyway');
    }

    /**
     * CENSUS CORRECTION 1, asserted rather than asserted-about. The validator carries FOUR keys but
     * `country_id` and `state_id` are `'nullable'`, so the maximum bag is TWO -- and the boundary
     * is pinned both with and without the handler's own 0->null merge above it.
     */
    public function test_the_shipping_zone_store_refusal_names_both_failing_fields(): void
    {
        $rules = [
            'zone_name' => 'required',
            'country_id' => 'nullable',
            'state_id' => 'nullable',
            'shipping_method' => 'required',
        ];

        $flash = $this->refusalFlash($this->merchant, 'shipping-zone.store',
            ['country_id' => 0, 'state_id' => 0], route('shipping-zone.index'));

        $this->assertJoinedExactly(['country_id' => null, 'state_id' => null], $rules,
            $flash, 'ShippingZoneController::store');

        foreach (['zone name', 'shipping method'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the shipping zone refusal does not name '{$field}'");
        }

        /* THE CORRECTION ITSELF: four rule keys, two possible messages, measured both ways. A
           `required` landing on either nullable field moves this and must be looked at. */
        $this->assertSame(2, count(\Validator::make([], $rules)->getMessageBag()->all()),
            'the shipping zone bag is no longer 2 messages -- the brief said 4 because it counted '
            . 'rule KEYS, and a nullable rule can never produce a message. Re-read the rules.');
        $this->assertSame(2, count(\Validator::make(
            ['country_id' => null, 'state_id' => null], $rules)->getMessageBag()->all()),
            'the shipping zone bag changes size once the handler nulls country_id/state_id');

        $this->assertSame(0, (int) \DB::table('shipping_zones')->where('store_id', $this->store->id)->count(),
            'the refused shipping zone was created anyway');
    }

    public function test_the_tax_method_store_refusal_names_both_failing_fields(): void
    {
        $flash = $this->refusalFlash($this->merchant, 'taxes-method.store', [],
            route('taxes-method.index'));

        $this->assertJoinedExactly([], ['name' => 'required', 'tax_rate' => 'required'],
            $flash, 'TaxMethodController::store');

        $this->assertStringContainsString('tax rate', $flash,
            'the tax method store refusal still discards the tax rate message');

        $this->assertSame(0, (int) \DB::table('tax_methods')->where('store_id', $this->store->id)->count(),
            'the refused tax method was created anyway');
    }

    public function test_the_tax_method_update_refusal_names_both_failing_fields(): void
    {
        $mine = $this->taxMethodIn($this->store, 'Mine', '5');

        $flash = $this->refusalFlash($this->merchant, 'taxes-method.update',
            [], route('taxes-method.index'), 'put', [$mine->id]);

        $this->assertJoinedExactly([], ['name' => 'required', 'tax_rate' => 'required'],
            $flash, 'TaxMethodController::update');

        $this->assertStringContainsString('tax rate', $flash,
            'the tax method update refusal still discards the tax rate message');

        /* AND THE RATE IS UNTOUCHED. Asserted separately from the name, because a change that
           kept the name while moving the rate is a different defect wearing the same green tick. */
        (new TaxMethod)->flushCache();
        $row = \DB::table('tax_methods')->where('id', $mine->id)->first();
        $this->assertSame('Mine', (string) $row->name, 'the refused update renamed the tax method');
        $this->assertSame('5', (string) $row->tax_rate, 'the refused update rewrote the tax RATE');
    }

    /**
     * THE BIGGEST MERCHANT-FACING WIN IN THIS GROUP: three `required` rules, so TWO of the three
     * messages were discarded on a screen whose three fields must agree with each other.
     */
    public function test_the_tax_option_settings_refusal_names_all_three_failing_fields(): void
    {
        $flash = $this->refusalFlash($this->merchant, 'tax-option.settings', [],
            route('setting.index'));

        $this->assertJoinedExactly([], [
            'price_type' => 'required',
            'shop_price' => 'required',
            'checkout_price' => 'required',
        ], $flash, 'TaxOptionController::taxSettings');

        foreach (['price type', 'shop price', 'checkout price'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the tax option refusal does not name '{$field}'");
        }
        $this->assertSame(3, substr_count($flash, 'is required'),
            'the tax option refusal does not carry all three messages');

        $this->assertSame(0, (int) \DB::table('tax_options')->where('store_id', $this->store->id)->count(),
            'the refused tax option post wrote settings anyway');
    }

    /**
     * CURRENCY, DRIVEN AS THE PLATFORM OPERATOR -- census correction 2. Two shapes are driven,
     * because the two that matter on this form are a blank post and a DUPLICATE post, and the
     * second is the one a real operator hits.
     */
    public function test_the_currency_store_refusal_names_all_three_failing_fields(): void
    {
        $rules = [
            'name' => 'required|unique:currency,name',
            'code' => 'required|unique:currency,code',
            'symbol' => 'required|unique:currency,symbol',
        ];

        $flash = $this->refusalFlash($this->operator, 'currency.store', [], route('currency.index'));
        $this->assertJoinedExactly([], $rules, $flash, 'CurrencyController::store (blank)');

        foreach (['name', 'code', 'symbol'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the currency store refusal does not name '{$field}'");
        }

        /* THE DUPLICATE POST. All three values collide, so all three `unique` rules fail and
           first() reported one of three. */
        \DB::table('currency')->insert(['name' => 'Dollar', 'code' => 'USD', 'symbol' => '$']);
        $dupe = ['name' => 'Dollar', 'code' => 'USD', 'symbol' => '$'];

        $flash = $this->refusalFlash($this->operator, 'currency.store', $dupe, route('currency.index'));
        $this->assertJoinedExactly($dupe, $rules, $flash, 'CurrencyController::store (duplicate)');
        $this->assertSame(3, substr_count($flash, 'already been taken'),
            'the duplicate currency post does not report all three collisions');

        $this->assertSame(1, (int) \DB::table('currency')->count(),
            'the refused currency post inserted a row anyway');
    }

    public function test_the_currency_update_refusal_names_all_three_failing_fields(): void
    {
        $id = (int) \DB::table('currency')->insertGetId([
            'name' => 'Euro', 'code' => 'EUR', 'symbol' => '#',
        ]);

        $flash = $this->refusalFlash($this->operator, 'currency.update', [],
            route('currency.index'), 'put', [$id]);

        $this->assertJoinedExactly([], [
            'name' => 'required|unique:currency,name,' . $id,
            'code' => 'required|unique:currency,code,' . $id,
            'symbol' => 'required|unique:currency,symbol,' . $id,
        ], $flash, 'CurrencyController::update');

        foreach (['name', 'code', 'symbol'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the currency update refusal does not name '{$field}'");
        }

        $row = \DB::table('currency')->where('id', $id)->first();
        $this->assertSame('Euro', (string) $row->name, 'the refused update rewrote the currency');
    }

    /**
     * THE ONE-MESSAGE CONTROL, driven on the two sites where a real merchant most often lands
     * there: an edit form where one field was cleared. See `assertFlashedExactlyOneMessage()` for
     * why this group needs it reconstructed rather than inherited.
     */
    public function test_a_partially_filled_form_still_flashes_exactly_the_one_remaining_message(): void
    {
        $shipping = $this->shippingClassIn($this->store, 'Mine');
        $tax = $this->taxMethodIn($this->store, 'Mine', '5');

        $flash = $this->refusalFlash($this->merchant, 'shipping.update',
            ['name' => 'Renamed'], route('shipping.index'), 'put', [$shipping->id]);
        $this->assertFlashedExactlyOneMessage(['name' => 'Renamed'],
            ['name' => 'required', 'description' => 'required'],
            $flash, 'ShippingController::update (description only)');

        $flash = $this->refusalFlash($this->merchant, 'taxes-method.update',
            ['name' => 'Renamed'], route('taxes-method.index'), 'put', [$tax->id]);
        $this->assertFlashedExactlyOneMessage(['name' => 'Renamed'],
            ['name' => 'required', 'tax_rate' => 'required'],
            $flash, 'TaxMethodController::update (tax_rate only)');

        /* AND NEITHER ROW MOVED -- the refusal is still above the write. */
        (new Shipping)->flushCache();
        (new TaxMethod)->flushCache();
        $this->assertSame('Mine', (string) \DB::table('shippings')->where('id', $shipping->id)->value('name'));
        $this->assertSame('Mine', (string) \DB::table('tax_methods')->where('id', $tax->id)->value('name'));
    }

    // =============================================================================================
    //  2. THE REFUSALS DID NOT WIDEN -- the trap, driven at all eight sites
    // =============================================================================================

    /**
     * A VALID post still succeeds at every one of the eight sites. This is the positive control
     * for the trap: the change may touch the WORDING of a refusal that already happened, never
     * WHETHER a refusal happens. If a dormant validator had been given a `fails()` guard, or a rule
     * had moved, these rows would stop being written.
     */
    public function test_a_valid_post_still_succeeds_at_every_site_this_change_touched(): void
    {
        $mineShipping = $this->shippingClassIn($this->store, 'Existing class');
        $mineTax = $this->taxMethodIn($this->store, 'Existing method', '7');
        $taxId = $this->taxGroupId($this->store);
        $currencyId = (int) \DB::table('currency')->insertGetId([
            'name' => 'Pound', 'code' => 'GBP', 'symbol' => '@',
        ]);

        $cases = [
            ['merchant', 'shipping.store', 'post', [], route('shipping.index'),
                ['name' => 'Bulky', 'description' => 'Bulky goods']],
            ['merchant', 'shipping.update', 'put', [$mineShipping->id], route('shipping.index'),
                ['name' => 'Renamed class', 'description' => 'Still described']],
            ['merchant', 'shipping-zone.store', 'post', [], route('shipping-zone.index'),
                ['zone_name' => 'Damascus', 'country_id' => 0, 'state_id' => 0,
                 'shipping_method' => ['flat_rate']]],
            ['merchant', 'taxes-method.store', 'post', [], route('taxes-method.index'),
                ['name' => 'VAT', 'tax_rate' => '11', 'tax_id' => $taxId, 'priority' => 1]],
            ['merchant', 'taxes-method.update', 'put', [$mineTax->id], route('taxes-method.index'),
                ['name' => 'VAT renamed', 'tax_rate' => '13', 'priority' => 1]],
            ['merchant', 'tax-option.settings', 'post', [], route('setting.index'),
                ['price_type' => 'inclusive', 'shop_price' => 'including',
                 'checkout_price' => 'including']],
            ['operator', 'currency.store', 'post', [], route('currency.index'),
                ['name' => 'Lira', 'code' => 'SYP', 'symbol' => '%']],
            ['operator', 'currency.update', 'put', [$currencyId], route('currency.index'),
                ['name' => 'Sterling', 'code' => 'GBP', 'symbol' => '@']],
        ];

        foreach ($cases as [$who, $route, $method, $params, $referer, $payload]) {
            $principal = $who === 'operator' ? $this->operator : $this->merchant;

            $response = $this->actingAs($principal)->from($referer)
                ->{$method}(route($route, $params), $payload);

            $response->assertRedirect($referer);

            $this->assertSame('', (string) $response->getSession()->get('error'),
                "{$route} REFUSED a valid post ('"
                . (string) $response->getSession()->get('error')
                . "'). The repair was supposed to change the message of an existing refusal, not "
                . 'create a new one.');
            $this->assertNotSame('', (string) $response->getSession()->get('success'),
                "{$route} did not report success for a valid post");
        }

        /* AND THE WRITES REALLY LANDED, read raw past the model cache. */
        (new Shipping)->flushCache();
        (new TaxMethod)->flushCache();
        (new TaxOption)->flushCache();

        $this->assertSame(1, (int) \DB::table('shippings')
            ->where('store_id', $this->store->id)->where('name', 'Bulky')->count());
        $this->assertSame('Renamed class', (string) \DB::table('shippings')
            ->where('id', $mineShipping->id)->value('name'));
        $this->assertSame(1, (int) \DB::table('shipping_zones')
            ->where('store_id', $this->store->id)->where('zone_name', 'Damascus')->count());
        $this->assertSame(1, (int) \DB::table('tax_methods')
            ->where('store_id', $this->store->id)->where('name', 'VAT')->count());
        $this->assertSame('VAT renamed', (string) \DB::table('tax_methods')
            ->where('id', $mineTax->id)->value('name'));
        $this->assertGreaterThan(0, (int) \DB::table('tax_options')
            ->where('store_id', $this->store->id)->count());
        $this->assertSame(1, (int) \DB::table('currency')->where('code', 'SYP')->count());
        $this->assertSame('Sterling', (string) \DB::table('currency')
            ->where('id', $currencyId)->value('name'));
    }

    /**
     * THE WIDENED MESSAGE MAY NOT REACH A CALLER THE GATE REFUSES.
     *
     * Every one of the eight sites sits BELOW its handler's permission gate, so widening the
     * wording cannot disclose a form's field list to a principal who may not use the form. Driven
     * for a merchant with no grants at all, and -- because the shipping and tax-method writes
     * refuse the operator EXPLICITLY, citing the F157 precedent -- for the operator too.
     *
     * `tax-option.settings` IS DELIBERATELY ABSENT FROM THE OPERATOR LIMB, and that is finding (b)
     * in the class docblock, not an oversight here: that handler has no operator check, so the
     * operator reaches its validator. `test_the_reported_authorization_gaps_are_still_exactly_as_reported`
     * pins it.
     */
    public function test_a_caller_the_gate_refuses_never_sees_the_widened_message(): void
    {
        $ungated = $this->makeStaff(User::find($this->merchant->id),
            ['current_store' => $this->store->id]);
        $ungated = User::find($ungated->id);

        $merchantSites = [
            ['shipping.store', 'post', [], route('shipping.index'), []],
            ['shipping-zone.store', 'post', [], route('shipping-zone.index'), []],
            ['taxes-method.store', 'post', [], route('taxes-method.index'), []],
            ['tax-option.settings', 'post', [], route('setting.index'), []],
        ];

        foreach ($merchantSites as [$route, $method, $params, $referer, $payload]) {
            $response = $this->actingAs($ungated)->from($referer)
                ->{$method}(route($route, $params), $payload);

            $flash = (string) $response->getSession()->get('error');

            $this->assertStringContainsStringIgnoringCase('permission den', $flash,
                "{$route} let a principal with NO grants past the gate");
            $this->assertStringNotContainsString('is required', $flash,
                "{$route} leaked validation text to a principal the gate refuses. The permission "
                . 'check must stay above the validator.');
        }

        /* The operator limb: shipping and tax-method writes refuse them by name. */
        foreach ([
            ['shipping.store', route('shipping.index')],
            ['taxes-method.store', route('taxes-method.index')],
        ] as [$route, $referer]) {
            $response = $this->actingAs($this->operator)->from($referer)->post(route($route), []);
            $flash = (string) $response->getSession()->get('error');

            $this->assertStringContainsStringIgnoringCase('permission den', $flash,
                "{$route} stopped refusing the platform operator");
            $this->assertStringNotContainsString('is required', $flash,
                "{$route} leaked validation text to the platform operator");
        }
    }

    /**
     * BOTH UPDATES RUN THEIR VALIDATOR ABOVE THEIR STORE PREDICATE, so the refusal is reachable
     * against a foreign id. The widening adds no disclosure there -- the bag names only the
     * caller's own posted fields -- and the predicate below still refuses the row. Driven with a
     * VALID payload, which is the only shape that gets as far as the predicate.
     */
    public function test_a_foreign_id_update_still_answers_not_found_and_leaves_the_row_untouched(): void
    {
        [$other, $otherStore] = $this->commerceTenant();

        $theirShipping = $this->shippingClassIn($otherStore, 'Theirs');
        $theirTax = $this->taxMethodIn($otherStore, 'Theirs', '9');

        $response = $this->actingAs($this->merchant)->from(route('shipping.index'))
            ->put(route('shipping.update', [$theirShipping->id]),
                ['name' => 'Stolen', 'description' => 'Stolen']);
        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('not found',
            (string) $response->getSession()->get('error'),
            'a valid payload against a FOREIGN shipping class was not refused by the predicate');

        $response = $this->actingAs($this->merchant)->from(route('taxes-method.index'))
            ->put(route('taxes-method.update', [$theirTax->id]),
                ['name' => 'Stolen', 'tax_rate' => '99', 'priority' => 1]);
        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('not found',
            (string) $response->getSession()->get('error'),
            'a valid payload against a FOREIGN tax method was not refused by the predicate');

        (new Shipping)->flushCache();
        (new TaxMethod)->flushCache();

        $this->assertSame('Theirs', (string) \DB::table('shippings')
            ->where('id', $theirShipping->id)->value('name'));
        $this->assertSame((int) $otherStore->id, (int) \DB::table('shippings')
            ->where('id', $theirShipping->id)->value('store_id'),
            'the foreign shipping class was MOVED into the caller store');

        $theirRow = \DB::table('tax_methods')->where('id', $theirTax->id)->first();
        $this->assertSame('Theirs', (string) $theirRow->name);
        $this->assertSame('9', (string) $theirRow->tax_rate,
            'the foreign tax method RATE was rewritten');
        $this->assertSame((int) $otherStore->id, (int) $theirRow->store_id,
            'the foreign tax method was MOVED into the caller store');

        $this->assertNotSame($other->id, $this->merchant->id);
    }

    // =============================================================================================
    //  3. THE RATCHET over all eight sites, and the shape controls
    // =============================================================================================

    /**
     * No owned controller may go back to `$messages->first()`, and each must carry the join at
     * every one of its refusal sites. The behavioural tests above already drive all eight, so this
     * is the completeness half: it catches a NINTH site arriving unrepaired.
     */
    public function test_no_owned_controller_flashes_only_the_first_validation_message(): void
    {
        $total = 0;

        foreach (self::OWNED as $relative => $sites) {
            $source = $this->codeWithoutComments($relative);

            /* THE PREMISE: the measured site count still covers the file. */
            $this->assertSame($sites, substr_count($source, 'getMessageBag()'),
                "{$relative} no longer has exactly {$sites} validator refusal sites, so the counts "
                . 'below no longer cover the file. Re-read the handler before trusting this test.');

            $this->assertSame(0, substr_count($source, "->with('error', \$messages->first())"),
                "{$relative} is back to flashing only the FIRST validation message, which leaves "
                . 'every other failing field unnamed (WCAG 3.3.1). Use '
                . "implode(' ', \$messages->all()).");

            $this->assertSame($sites, substr_count($source, "implode(' ', \$messages->all())"),
                "all {$sites} refusals in {$relative} must flash the JOINED message bag");

            $total += $sites;
        }

        $this->assertSame(8, $total, 'the eight measured refusal sites are no longer eight');
    }

    /**
     * THE TRAP, pinned on the source. `AppSettingController` builds six validators and consults
     * two; activating a dormant one would start REFUSING posts that succeed today. In these five
     * files every validator built is already consulted, so there is nothing dormant to activate --
     * and if a later pass adds one, this fails rather than letting it ride in on this pattern.
     */
    public function test_no_owned_controller_gained_a_newly_consulted_validator(): void
    {
        foreach (array_keys(self::OWNED) as $relative) {
            $source = $this->codeWithoutComments($relative);

            $built = substr_count($source, 'Validator::make(');
            $consulted = substr_count($source, '->fails()');

            $this->assertSame($built, $consulted,
                "{$relative} builds {$built} validators and consults {$consulted}. A validator "
                . 'built and never checked is dormant: giving it a fails() guard would start '
                . 'REFUSING posts that succeed today, which is a business-rule change and not '
                . 'this repair. (AppSettingController is the live example: 6 built, 2 consulted.)');
        }
    }

    /** The response shape is untouched on every owned file. */
    public function test_the_refusal_shape_did_not_change_on_any_owned_controller(): void
    {
        foreach (self::OWNED as $relative => $sites) {
            $source = $this->codeWithoutComments($relative);

            foreach ([
                'withErrors(' => 'nothing renders it: the refusal redirects to a different page, '
                    . 'which consumes the flash',
                'withInput(' => 'it cannot be read through this architecture -- see '
                    . 'ModalFormRefusalFeedbackTest::test_the_refusal_really_does_discard_the_merchants_input',
                'expectsJson(' => 'that is the modal-preserving redesign, which the review '
                    . 'deferred as Phase 2.5',
            ] as $needle => $why) {
                $this->assertSame(0, substr_count($source, $needle),
                    "{$relative} started calling {$needle}: {$why}.");
            }

            /* And the flash key is still `error` at every refusal site. */
            $this->assertSame($sites,
                substr_count($source, "->with('error', implode(' ', \$messages->all()))"),
                "{$relative} changed the flash key or the redirect at a validation refusal");
        }
    }

    /**
     * THE FOUR FINDINGS THIS SLICE REPORTED AND DID NOT REPAIR, pinned as facts so the next reader
     * meets them as measurements rather than as prose -- and so that if somebody closes one, this
     * reddens and the docblock gets corrected instead of going stale.
     *
     * None of them is a message change, and repairing any of them would change WHETHER a post is
     * accepted, which this slice must not do.
     */
    public function test_the_reported_authorization_gaps_are_still_exactly_as_reported(): void
    {
        $currency = $this->codeWithoutComments('app/Http/Controllers/CurrencyController.php');
        $taxOption = $this->codeWithoutComments('app/Http/Controllers/TaxOptionController.php');

        /* (a) index() checks the type; store() and update() do not. */
        $this->assertSame(1, substr_count($currency, "type == 'super admin'"),
            'CurrencyController no longer has exactly one super-admin type check (it was on '
            . 'index() alone). Finding (a) has moved -- re-read the controller.');

        /*
         * (b) taxSettings has NO operator limb, unlike the shipping and tax-method writes, which
         * refuse the operator by name and cite the F157 precedent.
         *
         * THE CLASSIFICATION STAGE GOT THIS HALF-WRONG AND THE DRIVE CAUGHT IT. It reported the
         * operator "reached the validator". They do NOT, today: the only gate is
         * `isAbleTo('Manage Tax-option')`, and Laratrust has no super-admin bypass, so an operator
         * holding no role is refused -- the FIRST assertion below. The gap is LATENT, not live:
         * `Manage Tax-option` exists as a Permission ROW (`Utility.php` `$arrPermissions`) while
         * being absent from `$superadminNewPermission`, so nothing grants it to the super-admin
         * role by default -- but the role editor can tick it, and the SECOND assertion drives
         * exactly that principal and shows the write landing on the merchant's own store.
         */
        $this->assertSame(0, substr_count($taxOption, 'isOperator'),
            'TaxOptionController gained an operator check: finding (b) is closed and this '
            . "file's docblock plus test_a_caller_the_gate_refuses... must be updated.");

        $response = $this->actingAs($this->operator)->from(route('setting.index'))
            ->post(route('tax-option.settings'), []);
        $this->assertStringContainsStringIgnoringCase('permission den',
            (string) $response->getSession()->get('error'),
            'an operator holding no tax-option grant now reaches taxSettings. isAbleTo() used to '
            . 'refuse them for want of a super-admin bypass; something gave them one.');

        /* NOW TICK THE PERMISSION, which the role editor can do because the row is seeded. */
        $operator = User::find($this->operator->id);
        $operator->current_store = $this->store->id;
        $operator->save();
        $this->grantPermission(User::find($operator->id), 'Manage Tax-option');
        $operator = User::find($operator->id);

        $operatorFlash = $this->refusalFlash($operator, 'tax-option.settings', [],
            route('setting.index'));
        $this->assertStringContainsString('is required', $operatorFlash,
            'an operator granted Manage Tax-option no longer reaches the validator, so finding '
            . '(b) is closed and the docblock must be corrected');

        $this->actingAs($operator)->from(route('setting.index'))
            ->post(route('tax-option.settings'), [
                'price_type' => 'inclusive', 'shop_price' => 'including',
                'checkout_price' => 'including',
            ]);
        (new TaxOption)->flushCache();
        $this->assertGreaterThan(0, (int) \DB::table('tax_options')
            ->where('store_id', $this->store->id)->count(),
            'a granted operator can no longer rewrite the MERCHANT store\'s tax settings, so '
            . 'finding (b) is closed -- correct the docblock rather than deleting this.');

        /* (c) the write is gated on the read-shaped name, and no finer one is seeded. */
        $this->assertSame(1, substr_count($taxOption, "isAbleTo('Manage Tax-option')"),
            'TaxOptionController no longer gates taxSettings on Manage Tax-option');
        $this->assertNull(\App\Models\Permission::where('name', 'Edit Tax-option')->first(),
            'an Edit Tax-option permission now exists, so finding (c) has a finer name to use');

        /* (d) the dead import. */
        $this->assertStringContainsString('use App\Http\Controllers\CurrencyController;',
            (string) file_get_contents(base_path('routes/customer.php')),
            'routes/customer.php no longer imports CurrencyController -- finding (d) is closed');
        $this->assertSame(0, substr_count(
            (string) file_get_contents(base_path('routes/customer.php')), 'CurrencyController::class'),
            'routes/customer.php now ROUTES CurrencyController, which would put a global '
            . 'platform table on a storefront surface. Finding (d) was that the import was dead.');
    }
}
