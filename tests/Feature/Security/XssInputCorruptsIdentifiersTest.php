<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `XSS.php` SILENTLY CORRUPTS VALID E-MAIL ADDRESSES, on every `web` form.
 * =================================================================================================
 *
 * Owner instruction of 2026-11-07, item 3: "`XSS.php` corrupting valid customer email addresses."
 *
 * REPRODUCTION ONLY IN THIS FILE. The middleware is a Phase 01 control on the whole `web` group
 * and the owner's instruction in the same message is "Do not weaken existing Phase 01 guards", so
 * this file MEASURES the corruption and PINS the protective behaviour that any fix must keep. The
 * fix itself is proposed to the owner rather than applied, because the blast radius is every form
 * in the application and the change is to a security control.
 *
 * EVERY TEST HERE PASSES, AND THAT IS DELIBERATE. The three that document the defect assert the
 * WRONG values they measured, not the right ones. A test that failed on purpose would be an
 * UNDECLARED FAILURE, and `.github/known-test-outcomes.json` asserts the outcome set in both
 * directions -- so it would break the gate for every later change on this branch. Declaring it in
 * that manifest instead would expand the accepted-failure set, which is the thing the manifest
 * exists to keep small. Each pinning test says in its own message that it is what should be
 * rewritten when the fix lands; when `XSS.php` is repaired, those three fail, and that failure is
 * the notification.
 *
 * THE MECHANISM, `app/Http/Middleware/XSS.php:53-59`:
 *
 *     array_walk_recursive($input, function (&$value) {
 *         if (is_string($value)) {
 *             $value = htmlspecialchars_decode($value);
 *             $value = str_replace(['&lt;', '&gt;', 'javascript', 'alert'], '', $value);
 *         }
 *     });
 *     $request->merge($input);
 *
 * A bare SUBSTRING strip over every string input, before validation and before the insert. So any
 * value containing the four letters `alert` or the ten letters `javascript` is rewritten:
 *
 *     alerts@company.com   ->  s@company.com
 *     javascript@x.com     ->  @x.com
 *
 * `alerts@` is not a contrived address — it is the shape of a monitoring or notification mailbox.
 * The address is the key every recovery flow in this application uses, and the merchant is never
 * told it was changed: validation sees the already-rewritten value, so `email` passes and the row
 * is written with the wrong address.
 *
 * CLAUDE.md ALREADY RECORDS THAT THIS IS NOT AN ESCAPING LAYER: the `htmlspecialchars_decode()`
 * turns an encoded `&lt;` into a raw `<` BEFORE the strip looks for `&lt;`, and a raw `<` was never
 * a target — so `<img src=x onerror=...>` passes through whole as long as it avoids two words. The
 * cost is therefore paid without the protection being real.
 */
class XssInputCorruptsIdentifiersTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Customer');
        $this->merchant = User::find($this->merchant->id);
    }

    private function create(string $email, string $first = 'Mona'): void
    {
        $this->actingAs($this->merchant)->post('/customer', [
            'first_name' => $first,
            'last_name' => 'Tester',
            'email' => $email,
            'mobile' => '555000555',
            'password' => 'a-long-enough-password',
            'status' => '1',
        ]);
    }

    private static function rows()
    {
        return Customer::withoutGlobalScopes();
    }

    // =============================================================================================
    //  THE CORRUPTION — PINNED AS IT IS, not asserted as it should be
    // =============================================================================================
    //
    // These three assert the CURRENT, DEFECTIVE values. That is deliberate and it is the project's
    // own pattern for an open question: a test that failed on purpose would be an UNDECLARED
    // FAILURE, and `.github/known-test-outcomes.json` asserts the outcome set in BOTH directions,
    // so it would break the gate for every later change on this branch. Declaring it in the
    // manifest instead would expand the accepted-failure set, which is the signal that manifest
    // exists to keep small.
    //
    // So each test below states the wrong value it measured and says, in its own message, that it
    // is what should be rewritten when the fix lands. When `XSS.php` is repaired these three fail,
    // and that failure is the notification.

    public function test_an_address_containing_alert_is_corrupted_today(): void
    {
        $this->create('alerts@company.com');

        $stored = self::rows()->where('store_id', $this->store->id)->first();

        $this->assertNotNull($stored,
            'premise: no customer row was written at all, so nothing can be said about the address');

        $this->assertSame('s@company.com', $stored->email,
            'OPEN DEFECT, PINNED. The merchant typed alerts@company.com and the application stored '
            . '"s@company.com": XSS.php:58 strips the substring "alert" from every web input BEFORE '
            . 'validation, so validation only ever saw the rewritten value and the row was written '
            . 'wrong silently. IF THIS TEST NOW FAILS, the middleware has been repaired and this '
            . 'test is what should be rewritten to assert the address is preserved');
    }

    /**
     * A SHARPER CASE THAN THE ONE ABOVE, and my first draft of this test got it wrong — it
     * predicted a corrupted row and the measurement showed **no row at all**.
     *
     * When the stripped substring is the WHOLE local part, `javascript@x.example` becomes
     * `@x.example`, which then fails the handler's own `email` rule. So the merchant is not given
     * a wrong row: they are **refused**, and told that the address they typed is not a valid
     * e-mail address — when it is. There is nothing in the message, the form or the logs
     * connecting that refusal to a middleware that edited the value before validation saw it.
     *
     * Recorded this way round because that is what the application does.
     */
    public function test_an_address_whose_local_part_is_the_stripped_word_is_refused_as_invalid(): void
    {
        $response = $this->actingAs($this->merchant)->post('/customer', [
            'first_name' => 'Mona',
            'last_name' => 'Tester',
            'email' => 'javascript@x.example',
            'mobile' => '555000555',
            'password' => 'a-long-enough-password',
            'status' => '1',
        ]);

        $this->assertNull(self::rows()->where('store_id', $this->store->id)->first(),
            'OPEN DEFECT, PINNED. A row WAS written for javascript@x.example — so the middleware '
            . 'no longer strips the local part away, which means it has been repaired and this '
            . 'test is what should be rewritten');

        $response->assertSessionHasErrors('email');

        // And the control: an address that contains neither substring is accepted, so the refusal
        // above is the strip and not something else about this payload.
        $this->create('ordinary@x.example', 'Control');
        $this->assertNotNull(self::rows()->where('email', 'ordinary@x.example')->first(),
            'positive control: an address with no stripped substring is accepted, so the refusal '
            . 'above is attributable to the strip');
    }

    /**
     * It is not only the address. Any field a merchant types is rewritten the same way, so a real
     * person whose name contains either substring has their name silently altered.
     */
    public function test_a_name_containing_alert_is_corrupted_today(): void
    {
        $this->create('ordinary@example.test', 'Alberto-alert');

        $stored = self::rows()->where('store_id', $this->store->id)->first();

        $this->assertNotNull($stored, 'premise: no customer row was written');

        $this->assertSame('Alberto-', $stored->first_name,
            'OPEN DEFECT, PINNED. "Alberto-alert" was stored as "Alberto-". If this test now '
            . 'fails, the middleware has been repaired — rewrite it');
    }

    // =============================================================================================
    //  WHAT ANY FIX MUST KEEP — pinned so a repair cannot quietly become a removal
    // =============================================================================================

    /**
     * These two assert the behaviour that is worth something, so that whatever replaces the bare
     * substring strip is measured against it rather than against nothing. They PASS today and must
     * keep passing.
     *
     * Deliberately NOT asserted here: that `&lt;` and `&gt;` are stripped. CLAUDE.md records that
     * `htmlspecialchars_decode()` runs FIRST and turns an encoded `&lt;` into a raw `<` before the
     * strip looks for the encoded form, so that pair is already ineffective and pinning it would
     * pin a no-op as though it were a control.
     */
    public function test_a_javascript_url_scheme_is_still_neutralised(): void
    {
        $this->create('ok@example.test', 'javascript:void(0)');

        $stored = self::rows()->where('store_id', $this->store->id)->first();

        $this->assertNotNull($stored, 'premise: no customer row was written');
        $this->assertStringNotContainsString('javascript:', $stored->first_name,
            'a javascript: URL scheme reached storage intact. Whatever replaces the bare substring '
            . 'strip must still neutralise this shape');
    }

    public function test_an_alert_call_is_still_neutralised(): void
    {
        $this->create('ok2@example.test', 'alert(1)');

        $stored = self::rows()->where('store_id', $this->store->id)->first();

        $this->assertNotNull($stored, 'premise: no customer row was written');
        $this->assertStringNotContainsString('alert(', $stored->first_name,
            'an alert( call reached storage intact. Whatever replaces the bare substring strip '
            . 'must still neutralise this shape');
    }
}
