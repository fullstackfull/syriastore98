<?php

namespace Tests\Feature\Merchant;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-PKG-E — the customer WRITE path: create, archive, and the form a merchant types into.
 * =================================================================================================
 *
 * Found while opening the customer family for the Phase 2.5 premium pass. Three repairs here, and
 * one question deliberately NOT answered in code because it is the owner's to decide (§4 below).
 *
 * E-1. ARCHIVE THEN RE-ADD IS A DEAD END, and a merchant can reach it in two clicks.
 * -------------------------------------------------------------------------------------------------
 * `destroy()` is `$customer->delete()` and `Customer` uses `SoftDeletes`, so archiving sets
 * `deleted_at` and tells the merchant "Customer archived successfully."
 *
 * `store()` validates `'email' => '...|unique:customers,email'`. Laravel's `unique` rule queries
 * the table with no `deleted_at` predicate, so IT COUNTS ARCHIVED ROWS. The archived customer's
 * e-mail is therefore reserved forever, and the create form answers "The email has already been
 * taken" for an address the merchant cannot see anywhere in their own interface.
 *
 * There is no restore path either: no `restore` route, no archived-customer view, no
 * `withTrashed()` anywhere in `CustomerController`. So the merchant has archived a customer, wants
 * them back, and the product offers no route at all — neither re-create nor restore.
 *
 * The repair is `->whereNull('deleted_at')` on the rule: an ARCHIVED e-mail stops being reserved.
 * It does not change what a LIVE duplicate does, which is the half that stays as it is.
 *
 * E-2. THE RAW EXCEPTION MESSAGE WAS RENDERED TO THE MERCHANT.
 * -------------------------------------------------------------------------------------------------
 * `catch (\Exception $e) { ... ->with('error', 'Error: ' . $e->getMessage()) }`. A database
 * exception's message carries SQL, table and column names, and sometimes values. That is an
 * internal detail on a merchant's screen, and `'Error: '` is untranslated so an Arabic merchant
 * reads an English prefix in front of it. The exception is still logged in full -- the change is
 * what the SCREEN says, not what the operator can diagnose.
 *
 * E-3. VALIDATION WAS REPORTED TWICE, THE SECOND TIME IN ENGLISH ONLY.
 * -------------------------------------------------------------------------------------------------
 * `$request->validate()` already throws `ValidationException`, and Laravel's handler already
 * redirects back with the errors and the input. The handler then CAUGHT that exception and redid
 * the same redirect, adding `->with('error', 'Validation failed: ' . implode(', ', $errorMessages))`
 * -- an untranslated English sentence listing every message, on top of the per-field messages the
 * form already renders through `<x-ss.error-summary>` and `@error`. So a merchant with one bad
 * field saw the complaint twice, once in a language they may not read.
 *
 * E-4. WHAT IS **NOT** CHANGED, AND IS ESCALATED INSTEAD.
 * -------------------------------------------------------------------------------------------------
 * `unique:customers,email` is GLOBAL -- it has no `store_id` predicate -- while the storefront's
 * own registration is per-store (`CustomerLoginController:104`,
 * `Customer::where('email', ...)->where('store_id', $store->id)->count()`). So two stores having
 * the same shopper is a state the product already produces, and the admin form is the only place
 * that refuses it. It is also an existence oracle: a merchant can discover whether any address is
 * a customer anywhere on the platform.
 *
 * **IT IS LEFT ALONE ON PURPOSE.** `StorefrontRegistrationTakeoverTest`'s header records that on
 * the API register endpoint the same `unique` rule is what keeps the e-mail path to create-only --
 * there it is PROTECTIVE. Scoping uniqueness per store is therefore a business-policy decision
 * with authorization implications, which the owner's directive reserves to the owner, so the
 * cross-store behaviour is PINNED by a test here rather than altered.
 */
class CustomerCreateFormTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Customer');
        $this->merchant = User::find($this->merchant->id);

        $otherMerchant = $this->makeMerchant();
        $this->otherStore = $this->makeStore($otherMerchant);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Rania',
            'last_name' => 'Aswad',
            'email' => 'rania@example.test',
            'mobile' => '555000222',
            'password' => 'a-long-enough-password',
            'status' => '1',
        ], $overrides);
    }

    private function customerIn(Store $store, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Existing', 'last_name' => 'Shopper',
            'email' => $email, 'password' => bcrypt('x'),
            'store_id' => $store->id, 'created_by' => $store->created_by,
            'status' => 1,
        ]);
    }

    /** Through withoutGlobalScopes: Customer carries StoreScope AND SoftDeletingScope. */
    private static function rows()
    {
        return Customer::withoutGlobalScopes();
    }

    // =============================================================================================
    //  E-1 — archive, then add the same person again
    // =============================================================================================

    public function test_premise_archiving_a_customer_soft_deletes_rather_than_removing(): void
    {
        $customer = $this->customerIn($this->store, 'ghada@example.test');

        $response = $this->actingAs($this->merchant)->delete('/customer/' . $customer->id);
        $response->assertStatus(302);

        $row = self::rows()->find($customer->id);
        $this->assertNotNull($row, 'premise: the row is gone entirely, so this is not a soft delete '
            . 'and the uniqueness interaction below cannot exist');
        $this->assertNotNull($row->deleted_at,
            'premise: archiving must set deleted_at, or E-1 is not the defect described');
    }

    /**
     * THE DEAD END. Two clicks a merchant can perform, and no route out.
     */
    public function test_an_archived_customers_email_can_be_used_again(): void
    {
        $customer = $this->customerIn($this->store, 'ghada@example.test');
        $this->actingAs($this->merchant)->delete('/customer/' . $customer->id);

        $response = $this->actingAs($this->merchant)
            ->post('/customer', $this->payload(['email' => 'ghada@example.test']));

        $response->assertSessionHasNoErrors();

        $live = self::rows()->whereNull('deleted_at')
            ->where('store_id', $this->store->id)
            ->where('email', 'ghada@example.test')
            ->count();

        $this->assertSame(1, $live,
            "an ARCHIVED customer's e-mail is still reserved by `unique:customers,email`, which "
            . 'queries the table with no deleted_at predicate. The merchant archived this person, '
            . 'wants them back, and the product offers neither a re-create nor a restore: there is '
            . 'no restore route and no withTrashed() anywhere in CustomerController');
    }

    /**
     * POSITIVE CONTROL for the test above. A LIVE duplicate in the same store must STILL be
     * refused — otherwise the repair has not narrowed the rule to archived rows, it has removed it.
     */
    public function test_a_live_duplicate_in_the_same_store_is_still_refused(): void
    {
        $this->customerIn($this->store, 'nadia@example.test');

        $response = $this->actingAs($this->merchant)
            ->post('/customer', $this->payload(['email' => 'nadia@example.test']));

        $response->assertSessionHasErrors('email');

        $this->assertSame(1, self::rows()->where('email', 'nadia@example.test')->count(),
            'the uniqueness rule no longer refuses a LIVE duplicate, so the repair removed the '
            . 'rule rather than narrowing it to archived rows');
    }

    // =============================================================================================
    //  E-4 — the cross-store behaviour, PINNED rather than changed (owner decision)
    // =============================================================================================

    /**
     * This test asserts the CURRENT behaviour, which is a defect this slice deliberately does not
     * repair. It exists so the behaviour cannot drift while the owner decides, and so the decision
     * has a test to flip when it lands.
     */
    public function test_another_stores_live_email_is_still_refused_which_is_the_open_question(): void
    {
        $this->customerIn($this->otherStore, 'shared@example.test');

        $response = $this->actingAs($this->merchant)
            ->post('/customer', $this->payload(['email' => 'shared@example.test']));

        $response->assertSessionHasErrors('email');

        $this->assertSame(0, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'shared@example.test')->count(),
            'OPEN OWNER QUESTION, not a regression: `unique:customers,email` is global, so a '
            . "merchant cannot add a customer whose address exists in ANOTHER store -- while the "
            . 'storefront registration is per-store (CustomerLoginController:104), so that state '
            . 'is one the product already produces. Scoping it per store is a business-policy '
            . 'change with authorization implications (the same rule is PROTECTIVE on the API '
            . 'register endpoint, see StorefrontRegistrationTakeoverTest), so it is escalated '
            . 'rather than made. If this test starts failing, the decision has been implemented '
            . 'and this test is what should be rewritten.');
    }

    // =============================================================================================
    //  E-2 / E-3 — what the merchant is told when it goes wrong
    // =============================================================================================

    public function test_a_validation_failure_is_not_also_reported_as_an_untranslated_sentence(): void
    {
        $response = $this->actingAs($this->merchant)
            ->post('/customer', $this->payload(['email' => 'not-an-email']));

        $response->assertSessionHasErrors('email');

        $flash = session('error');
        $this->assertNotTrue(
            is_string($flash) && str_contains($flash, 'Validation failed'),
            'the handler still catches its own ValidationException and flashes '
            . '"Validation failed: ..." -- an untranslated English sentence listing every message, '
            . 'on top of the per-field messages the form already renders. The merchant is told '
            . 'twice, once in a language they may not read');
    }

    public function test_a_short_password_is_refused_and_the_form_says_the_rule(): void
    {
        $response = $this->actingAs($this->merchant)
            ->post('/customer', $this->payload(['password' => 'short']));

        $response->assertSessionHasErrors('password');

        // And the form states the rule up front, rather than making the merchant discover it by
        // submitting. The server rule is `min:8`.
        $form = $this->actingAs($this->merchant)->get('/customer/create');
        $form->assertStatus(200);
        $this->assertMatchesRegularExpression('/minlength="8"/', $form->getContent(),
            'the password field does not carry the server rule, so a merchant learns `min:8` only '
            . 'by submitting and being refused');
    }

    // =============================================================================================
    //  E-5 — the form itself: bilingual correctness and autofill
    // =============================================================================================

    /**
     * BIDI. In an Arabic (RTL) document a Latin e-mail address or a phone number inside an
     * `dir=rtl` input renders with its parts reordered -- a leading `+` jumps to the wrong end, and
     * `name@host.tld` can display as `tld.host@name`-shaped runs. The customer GRID already guards
     * its e-mail with `dir="ltr"` (grid.blade.php:159); the form a merchant TYPES the address into
     * did not.
     */
    public function test_the_email_and_mobile_inputs_are_marked_left_to_right(): void
    {
        $html = $this->actingAs($this->merchant)->get('/customer/create')->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]+name="email"[^>]+dir="ltr"|<input[^>]+dir="ltr"[^>]+name="email"/', $html,
            'the e-mail input is not marked dir="ltr", so in the Arabic interface the address the '
            . 'merchant types reorders as they type it');
        $this->assertMatchesRegularExpression(
            '/<input[^>]+name="mobile"[^>]+dir="ltr"|<input[^>]+dir="ltr"[^>]+name="mobile"/', $html,
            'the mobile input is not marked dir="ltr"');
    }

    /**
     * Autofill. Six fields, every one of them a standard autocomplete token, and none declared --
     * so a browser or password manager cannot fill a customer's details or offer a generated
     * password. `new-password` on the password field is what stops a manager offering the
     * MERCHANT's own saved credential for the customer being created.
     */
    public function test_the_form_declares_autocomplete_tokens(): void
    {
        $html = $this->actingAs($this->merchant)->get('/customer/create')->getContent();

        foreach ([
            'first_name' => 'given-name',
            'last_name' => 'family-name',
            'email' => 'email',
            'mobile' => 'tel',
            'password' => 'new-password',
        ] as $field => $token) {
            $this->assertMatchesRegularExpression(
                '/name="' . $field . '"[^>]*autocomplete="' . $token . '"'
                . '|autocomplete="' . $token . '"[^>]*name="' . $field . '"/',
                $html,
                "the {$field} input does not declare autocomplete=\"{$token}\"");
        }
    }

    public function test_submit_is_a_button_that_can_carry_an_icon(): void
    {
        $html = $this->actingAs($this->merchant)->get('/customer/create')->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]+type="submit"/', $html,
            'the submit control is still Form::submit(), which renders <input type="submit"> and '
            . 'cannot contain an icon or any other child element');
    }

    /**
     * AND IT MUST BE A DESCENDANT OF THE FORM **IN THE PARSED TREE**, which is a different claim
     * from "appears between the form's tags in the source".
     *
     * The first version of this test matched the form with a regex and asserted the button was in
     * the captured group. That CANNOT catch mis-nesting: a regex reads source order, so for
     * `<div class="card-body"><form>…</div><div class="card-footer"><button></div></form>` the
     * button IS between the tags textually while the markup is malformed and what the browser
     * builds depends on how its parser recovers. The first draft of this view had exactly that
     * shape -- `Form::open()` inside `.card-body`, the button in `.card-footer` -- and the regex
     * version passed against it.
     *
     * So this parses the document and walks ancestors. `DOMDocument` applies the same recovery an
     * HTML parser does, which is the point: it answers what a BROWSER would build, not what the
     * template happens to say.
     */
    public function test_every_control_is_a_descendant_of_the_form_in_the_parsed_document(): void
    {
        $html = $this->actingAs($this->merchant)->get('/customer/create')->getContent();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $forms = $xpath->query("//form[contains(@action, '/customer')]");
        $this->assertGreaterThan(0, $forms->length,
            'premise: no customer form was parsed out of the document');
        $form = $forms->item(0);

        $isInsideForm = static function (?\DOMNode $node) use ($form): bool {
            for ($n = $node; $n !== null; $n = $n->parentNode) {
                if ($n === $form) {
                    return true;
                }
            }
            return false;
        };

        $submits = $xpath->query("//button[@type='submit']");
        $this->assertGreaterThan(0, $submits->length, 'premise: no submit button was parsed');

        $submitInside = false;
        foreach ($submits as $button) {
            if ($isInsideForm($button)) {
                $submitInside = true;
                break;
            }
        }
        $this->assertTrue($submitInside,
            'the submit button is not a descendant of the form in the parsed document, so pressing '
            . 'it submits nothing. This is the failure a source-order assertion cannot see');

        foreach (['first_name', 'last_name', 'email', 'mobile', 'password', 'status'] as $field) {
            $nodes = $xpath->query("//*[@name='{$field}']");
            $this->assertGreaterThan(0, $nodes->length, "premise: no control named {$field}");

            $inside = false;
            foreach ($nodes as $node) {
                if ($isInsideForm($node)) {
                    $inside = true;
                    break;
                }
            }
            $this->assertTrue($inside,
                "the {$field} control is not a descendant of the form, so its value is never sent");
        }
    }

    // =============================================================================================
    //  F5 — the aria-describedby pairing, asserted HERE because nothing else asserts it
    // =============================================================================================

    /**
     * The view first claimed `ContextualFieldHelpTest` enforced this. It does not — that test
     * lists five PRODUCT fields and loads only the product create and edit screens, so these four
     * customer pairs were asserted by nothing. Raised by the tenant-authz review of this change.
     *
     * BOTH DIRECTIONS, which is the whole point of the pairing: a dangling `aria-describedby` is
     * worse than silence, because a screen reader announces neither the hint nor an error; and an
     * orphan hint that nothing references is decoration.
     */
    public function test_every_field_hint_is_bound_to_its_control_in_both_directions(): void
    {
        $html = $this->actingAs($this->merchant)->get('/customer/create')->getContent();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $describedBy = [];
        foreach (['email', 'mobile', 'password', 'status'] as $field) {
            $nodes = $xpath->query("//*[@name='{$field}'][@aria-describedby]");
            $this->assertGreaterThan(0, $nodes->length,
                "the {$field} control carries no aria-describedby, so its hint is not announced");
            $describedBy[$field] = $nodes->item(0)->getAttribute('aria-describedby');
        }

        // FORWARD: every id a control points at must exist.
        foreach ($describedBy as $field => $id) {
            $target = $xpath->query("//*[@id='" . $id . "']");
            $this->assertGreaterThan(0, $target->length,
                "the {$field} control's aria-describedby points at '{$id}', which is in no element "
                . '— a dangling reference announces nothing at all');
            $this->assertNotSame('', trim($target->item(0)->textContent),
                "the {$field} control's hint element '{$id}' is empty");
        }

        // BACKWARD: every hint this view renders must be referenced by a control.
        $hints = $xpath->query("//*[@data-ss-field-help]");
        $this->assertGreaterThan(0, $hints->length, 'premise: no field hints were rendered');
        foreach ($hints as $hint) {
            $id = $hint->getAttribute('id');
            $this->assertContains($id, array_values($describedBy),
                "the hint '{$id}' is referenced by no control on this page, so it is decoration");
        }
    }

    // =============================================================================================
    //  AUTHORIZATION — unchanged by this slice, asserted because the handler was edited
    // =============================================================================================

    public function test_a_principal_without_manage_customer_cannot_create(): void
    {
        $staff = $this->makeStaff($this->merchant);
        $staff->current_store = $this->store->id;
        $staff->save();

        $response = $this->actingAs(User::find($staff->id))->post('/customer', $this->payload());

        $response->assertStatus(302);
        $response->assertSessionHas('error');
        $this->assertSame(0, self::rows()->where('email', 'rania@example.test')->count(),
            'a staff user holding no customer permission created a customer');
    }

    public function test_a_created_customer_lands_in_the_callers_own_store(): void
    {
        $response = $this->actingAs($this->merchant)->post('/customer', $this->payload());
        $response->assertSessionHasNoErrors();

        $customer = self::rows()->where('email', 'rania@example.test')->firstOrFail();

        $this->assertSame($this->store->id, (int) $customer->store_id,
            'the new customer did not land in the creating merchant\'s own store');
        $this->assertNotSame($this->otherStore->id, (int) $customer->store_id,
            'positive control: the two stores are distinct, so the assertion above is meaningful');
    }

    /**
     * store_id comes from getCurrentStore(), never from the request. A posted store_id must be
     * ignored rather than honoured -- CLAUDE.md records four separate defects from a tenant
     * predicate built out of caller input.
     */
    public function test_a_posted_store_id_is_ignored(): void
    {
        $response = $this->actingAs($this->merchant)
            ->post('/customer', $this->payload(['store_id' => $this->otherStore->id]));
        $response->assertSessionHasNoErrors();

        $customer = self::rows()->where('email', 'rania@example.test')->firstOrFail();

        $this->assertSame($this->store->id, (int) $customer->store_id,
            'a posted store_id re-tenanted the new customer');
    }
}
