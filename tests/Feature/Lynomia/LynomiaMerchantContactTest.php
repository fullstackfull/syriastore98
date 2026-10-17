<?php

namespace Tests\Feature\Lynomia;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaContactVerification;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\MerchantContactVerification;
use Workdo\LynomiaChat\app\Support\MerchantContact;
use Tests\TestCase;

/**
 * B4. WHETHER A MERCHANT CAN BE REACHED, AND WHAT IT TAKES TO SAY SO.
 *
 * The blocker was that no merchant phone number on this installation was trustworthy:
 * `users.mobile` is unvalidated, self-registration writes an empty string, and Super Admin
 * store creation wrote the literal 7878787878 for every merchant it created. A WhatsApp
 * message addressed from that column reaches whoever actually holds the number, who is a
 * stranger, about a merchant's account.
 *
 * TWO PROPERTIES CARRY THE WEIGHT HERE.
 *
 * WELL-FORMED IS NOT VERIFIED. A number can parse perfectly and belong to somebody else -- a
 * typo in a merchant's own profile is a valid number for a stranger -- so the only thing that
 * admits a number is its holder answering a code sent to it, and the only thing that can set
 * that flag is this flow.
 *
 * NOTHING ELSE MAY SET IT. Not an operator, not a backfill, not mass assignment, and not a
 * merchant editing the field afterwards.
 */
class LynomiaMerchantContactTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
        config()->set('lynomia.platform_workspace_account_id', 9001);
        config()->set('lynomia.platform_workspace_sender_user_id', 501);
        config()->set('lynomia.platform_workspace_inbox_id', 31);
    }

    private function verification(): MerchantContactVerification
    {
        return app(MerchantContactVerification::class);
    }

    // ===================================================== the four states

    /**
     * EVERY VALUE THIS INSTALLATION ACTUALLY HAS IN THE COLUMN, classified. These are not
     * hypothetical categories: each is a population that exists in `users` today.
     *
     */
    #[DataProvider('contactStates')]
    public function test_a_contact_value_is_classified_the_same_way_everywhere(
        ?string $mobile,
        bool $verified,
        string $expectedState,
        ?string $expectedRefusal
    ): void {
        $merchant = $this->merchant();
        $merchant->forceFill([
            'mobile' => $mobile,
            'mobile_verified_at' => $verified ? now() : null,
        ])->save();

        $contact = MerchantContact::of($merchant->fresh());

        $this->assertSame($expectedState, $contact->state);
        $this->assertSame($expectedRefusal, $contact->refusal());
        $this->assertSame($expectedRefusal === null, $contact->reachable());
    }

    /**
     * STATE NAMES AS LITERALS, NOT AS CLASS CONSTANTS, and the reason is worth a line.
     *
     * PHPUnit evaluates a static data provider BEFORE the application boots, and this module
     * is PSR-4 registered at runtime by PackageServiceProvider::loadAddons -- so referencing
     * MerchantContact::VERIFIED here throws "class not found" at collection time and errors
     * every example in the file, including the ones that never touch the provider. The
     * example below pins the literals to the constants so they cannot drift apart.
     */
    public static function contactStates(): array
    {
        return [
            'valid and confirmed' => ['963900111222', true, 'verified', null],
            'valid, nobody confirmed it' => ['963900111222', false, 'unverified', 'phone_not_verified'],
            'formatted, confirmed' => ['+963 900 111 222', true, 'verified', null],
            'missing entirely' => [null, false, 'missing', 'no_phone_on_record'],
            'empty string, as self-registration writes' => ['', false, 'missing', 'no_phone_on_record'],
            'the Super Admin placeholder' => ['7878787878', false, 'placeholder', 'placeholder_phone'],
            // The same fabricated value with a country code in front of it. An exact
            // comparison admitted this as a well-formed twelve-digit number, which is what
            // anybody tidying the column would produce.
            'the placeholder with a country code' => ['+91 7878787878', false, 'placeholder', 'placeholder_phone'],
            'and confirmed, which must still not rescue it' => ['917878787878', true, 'placeholder', 'placeholder_phone'],
            'too short to dial' => ['1234', false, 'not_usable', 'phone_not_usable'],
            'too long to be a number' => ['9639001112223334445', false, 'not_usable', 'phone_not_usable'],
            // Something IS in the column, so it is not 'missing' -- it is a value that
            // cannot receive a message, which is a different thing for an operator to read.
            'not a number at all' => ['call me', false, 'not_usable', 'phone_not_usable'],
        ];
    }

    /** The literals above are the constants, and stay the constants. */
    public function test_the_state_names_the_provider_uses_are_the_ones_the_class_defines(): void
    {
        $this->assertSame('verified', MerchantContact::VERIFIED);
        $this->assertSame('unverified', MerchantContact::UNVERIFIED);
        $this->assertSame('missing', MerchantContact::MISSING);
        $this->assertSame('placeholder', MerchantContact::PLACEHOLDER);
        $this->assertSame('not_usable', MerchantContact::NOT_USABLE);
    }

    /**
     * A PLACEHOLDER IS NEVER VERIFIED, WHATEVER THE FLAG SAYS.
     *
     * The flag is set by one flow that cannot produce this state -- but a row written by hand,
     * a bad backfill or a future bug could, and "verified" must not be the answer for a number
     * that was never anybody's. The classification is read before the flag, not after.
     */
    public function test_the_flag_cannot_rescue_a_placeholder(): void
    {
        $merchant = $this->merchant();
        $merchant->forceFill(['mobile' => '7878787878', 'mobile_verified_at' => now()])->save();

        $contact = MerchantContact::of($merchant->fresh());

        $this->assertSame(MerchantContact::PLACEHOLDER, $contact->state);
        $this->assertFalse($contact->reachable());
        $this->assertSame('placeholder_phone', $contact->refusal());
    }

    // ===================================================== nothing invents a number

    /** Super Admin store creation wrote 7878787878 for every merchant it made. It writes nothing now. */
    public function test_super_admin_store_creation_no_longer_fabricates_a_number(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/StoreController.php'));

        $this->assertStringNotContainsString("'mobile' => '7878787878'", $source);
        $this->assertStringContainsString("'mobile' => null", $source);
    }

    /** The flag is not mass-assignable: a claim that somebody proved a number is not form input. */
    public function test_the_verified_flag_cannot_be_mass_assigned(): void
    {
        $merchant = $this->merchant();

        $merchant->fill(['mobile_verified_at' => now()])->save();

        $this->assertNull($merchant->fresh()->mobile_verified_at);
    }

    // ===================================================== proving a number

    public function test_a_merchant_confirms_a_number_by_answering_a_code_sent_to_it(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();

        $this->verification()->request($owner, $store, '+963 900 111 222');
        $challenge = LynomiaContactVerification::where('user_id', $owner->id)->firstOrFail();

        // The code is never readable from the row -- only its hash is kept, as for an account
        // claim. The test reaches for the plaintext the only way anything can: by being the
        // one that generated it.
        $code = $this->codeFor($challenge);

        $this->assertTrue($this->verification()->confirm($owner->fresh(), $code));

        $merchant = $owner->fresh();
        $this->assertSame('963900111222', $merchant->mobile, 'stored as digits, so a reformat is not a change');
        $this->assertNotNull($merchant->mobile_verified_at);
        $this->assertTrue(MerchantContact::of($merchant)->reachable());
        $this->assertSame(0, LynomiaContactVerification::count(), 'the code is single use');
    }

    /**
     * THE CANDIDATE DOES NOT TOUCH THE PROFILE UNTIL IT IS PROVED.
     *
     * A merchant who has confirmed one number and then asks about another must not lose the
     * confirmed one to a number nobody has answered on -- and must not acquire a verified flag
     * for it either.
     */
    public function test_an_unproved_candidate_does_not_replace_a_confirmed_number(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $owner->forceFill(['mobile' => '963900111222', 'mobile_verified_at' => now()])->save();

        $this->verification()->request($owner->fresh(), $store, '963900999888');

        $merchant = $owner->fresh();
        $this->assertSame('963900111222', $merchant->mobile);
        $this->assertNotNull($merchant->mobile_verified_at);
        $this->assertTrue(MerchantContact::of($merchant)->reachable());
    }

    #[DataProvider('unusableCandidates')]
    public function test_a_candidate_that_is_not_a_number_is_refused_before_anything_is_sent(string $candidate): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $before = count(Http::recorded());

        try {
            $this->verification()->request($owner, $store, $candidate);
            $this->fail("{$candidate} should not have been accepted");
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_CONFLICT, $e->kind);
        }

        $this->assertSame(0, LynomiaContactVerification::count());
        $this->assertCount($before, Http::recorded(), 'nothing may be sent to a value like that');
    }

    public static function unusableCandidates(): array
    {
        return [
            'empty' => [''],
            'the placeholder' => ['7878787878'],
            'too short' => ['1234'],
            'not a number' => ['call me'],
        ];
    }

    public function test_a_wrong_code_is_refused_and_costs_an_attempt(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $this->verification()->request($owner, $store, '963900111222');

        $this->assertFalse($this->verification()->confirm($owner->fresh(), '000000'));

        $challenge = LynomiaContactVerification::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame(1, $challenge->attempts);
        $this->assertNull($owner->fresh()->mobile_verified_at);
    }

    /** A hashed code with unlimited attempts is a slower brute force, not a closed one. */
    public function test_a_code_is_burned_after_too_many_wrong_answers(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $this->verification()->request($owner, $store, '963900111222');
        $challenge = LynomiaContactVerification::where('user_id', $owner->id)->firstOrFail();
        $code = $this->codeFor($challenge);

        for ($i = 0; $i < LynomiaContactVerification::MAX_ATTEMPTS; $i++) {
            $this->verification()->confirm($owner->fresh(), '000000');
        }

        // Even the RIGHT code is no good now.
        $this->expectException(LynomiaApiException::class);
        $this->verification()->confirm($owner->fresh(), $code);
    }

    public function test_an_expired_code_cannot_be_answered(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $this->verification()->request($owner, $store, '963900111222');
        $challenge = LynomiaContactVerification::where('user_id', $owner->id)->firstOrFail();
        $code = $this->codeFor($challenge);
        $challenge->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->expectException(LynomiaApiException::class);
        $this->verification()->confirm($owner->fresh(), $code);
    }

    /** Send is not a free way to make somebody's phone ring. */
    public function test_a_second_code_is_refused_until_the_cooldown_passes(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $this->verification()->request($owner, $store, '963900111222');

        try {
            $this->verification()->request($owner->fresh(), $store, '963900111222');
            $this->fail('a second code should have been refused');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_RATE_LIMITED, $e->kind);
        }
    }

    // ===================================================== the claim does not outlive the number

    /**
     * A NUMBER THAT CHANGED IS A NUMBER NOBODY HAS PROVED. Without this a merchant confirms a
     * number they hold and then quietly types another, and the flag says somebody proved a
     * number nobody has ever answered on.
     */
    public function test_editing_the_number_clears_the_confirmation(): void
    {
        $merchant = $this->merchant();
        $merchant->forceFill(['mobile' => '963900111222', 'mobile_verified_at' => now()])->save();

        $this->verification()->forgetIfChanged($merchant, '963900999888');

        $this->assertNull($merchant->fresh()->mobile_verified_at);
    }

    /** Reformatting the same number is not a change, so it does not cost the merchant their proof. */
    public function test_reformatting_the_same_number_keeps_the_confirmation(): void
    {
        $merchant = $this->merchant();
        $merchant->forceFill(['mobile' => '963900111222', 'mobile_verified_at' => now()])->save();

        $this->verification()->forgetIfChanged($merchant, '+963 900 111 222');

        $this->assertNotNull($merchant->fresh()->mobile_verified_at);
    }

    /** The profile editor is the path a merchant actually takes, so it is asserted end to end. */
    public function test_the_profile_editor_clears_the_confirmation_when_the_number_changes(): void
    {
        $merchant = $this->merchant();
        $merchant->forceFill(['mobile' => '963900111222', 'mobile_verified_at' => now()])->save();

        $this->actingAs($merchant->fresh())->post(route('update.account'), [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'mobile' => '963900999888',
        ]);

        $updated = $merchant->fresh();
        $this->assertSame('963900999888', $updated->mobile, 'the field still works as it always did');
        $this->assertNull($updated->mobile_verified_at, 'but the claim does not survive the edit');
    }

    // ===================================================== only a verified merchant is messaged

    public function test_an_operational_notice_is_refused_for_an_unverified_number(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['mobile' => '963900111222', 'mobile_verified_at' => null])->save();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();
        $before = count(Http::recorded());

        $notice = app(\Workdo\LynomiaChat\app\Services\PlatformNotifier::class)->notify(
            $link, 'integration_recovery', 'integration_broken', 'unverified:1', [], $this->operator()
        );

        $this->assertSame(LynomiaPlatformNotice::FAILED, $notice->status);
        $this->assertSame('phone_not_verified', $notice->failure_reason);
        $this->assertCount($before, Http::recorded(), 'nothing may be sent to a number nobody proved');
    }

    public function test_an_operational_notice_reaches_a_verified_number(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $owner->forceFill(['mobile' => '963900111222', 'mobile_verified_at' => now()])->save();
        $this->fakeLynomia(['account_id' => 7001]);
        $link = app(AccountProvisioner::class)->provision($store, $owner->fresh())->fresh();

        $notice = app(\Workdo\LynomiaChat\app\Services\PlatformNotifier::class)->notify(
            $link, 'integration_recovery', 'integration_broken', 'verified:1', [], $this->operator()
        );

        $this->assertSame(LynomiaPlatformNotice::SENT, $notice->status);
        $this->assertSame('963900111222', $notice->recipient_phone);
    }

    // ===================================================== tenant isolation

    public function test_a_merchant_cannot_verify_against_another_tenants_store(): void
    {
        [$owner] = $this->entitledTenant();
        [$otherOwner, $otherStore] = $this->entitledTenant();
        $this->fakeLynomia();

        $this->assertNotSame((int) $owner->id, (int) $otherOwner->id);

        try {
            $this->verification()->request($owner, $otherStore, '963900111222');
            $this->fail('a merchant must not act on a store they do not own');
        } catch (LynomiaApiException $e) {
            $this->assertSame(LynomiaApiException::KIND_NOT_PERMISSIBLE, $e->kind);
        }

        $this->assertSame(0, LynomiaContactVerification::count());
    }

    /** Neither the number nor the code is copied into the trail. */
    public function test_the_audit_trail_records_the_act_and_not_the_personal_data(): void
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia();
        $this->verification()->request($owner, $store, '963900111222');
        $challenge = LynomiaContactVerification::where('user_id', $owner->id)->firstOrFail();
        $this->verification()->confirm($owner->fresh(), $this->codeFor($challenge));

        $entries = AuditLog::query()->get();
        $this->assertTrue($entries->contains(fn ($e) => $e->action === 'lynomia_contact_verification_requested'));
        $this->assertTrue($entries->contains(fn ($e) => $e->action === 'lynomia_contact_verification_confirmed'));

        foreach ($entries as $entry) {
            $payload = json_encode($entry->new_values) . json_encode($entry->old_values);
            $this->assertStringNotContainsString('963900111222', (string) $payload);
        }
    }

    /**
     * THE CODE, READ WHERE THE MERCHANT READS IT: out of the message that was sent.
     *
     * The row keeps only a hash, so there is nowhere else to get it -- which is the property
     * being relied on here as much as it is the mechanic. Taking it from the outgoing request
     * also means these examples exercise the real path: a code that never reached the send is
     * a code the merchant never received, and `assertNotNull` below is what says so.
     */
    private function codeFor(LynomiaContactVerification $challenge): string
    {
        $code = null;

        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), '/messages')) {
                continue;
            }

            $body = json_decode((string) $request->body(), true);
            $sent = $body['template_params']['processed_params']['body']['1'] ?? null;

            if (is_string($sent) && Hash::check($sent, (string) $challenge->code_hash)) {
                $code = $sent;
            }
        }

        $this->assertNotNull($code, 'the challenge must have been sent to the merchant');

        return $code;
    }
}
