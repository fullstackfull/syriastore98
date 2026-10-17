<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaContactVerification;
use Workdo\LynomiaChat\app\Support\MerchantContact;

/**
 * A MERCHANT PROVING THEY HOLD THE NUMBER THEY GAVE US (B4).
 *
 * The register's B4 is that no merchant phone number on this installation is trustworthy:
 * the column is unvalidated, self-registration writes an empty string, and Super Admin store
 * creation wrote the literal 7878787878 for every merchant it created. A WhatsApp message
 * addressed from a column like that does not fail safely -- it reaches whoever actually holds
 * the number, who is a stranger, about a merchant's account.
 *
 * THE ONLY THING THAT MAKES A NUMBER TRUSTWORTHY IS ITS HOLDER ANSWERING ON IT. So that is
 * the whole mechanism: a code goes to the candidate number and comes back typed in by the
 * person who received it. Nothing else sets the flag. No operator can mark a merchant
 * verified, no backfill infers it from an old value, and nothing derives it from the number
 * merely looking right -- a typo in a merchant's own profile is a perfectly valid number
 * belonging to somebody else.
 *
 * WHO SENDS IT, AND WHY IT IS NOT THE MERCHANT'S OWN GATEWAY. This installation already has
 * OTP machinery, and it is the wrong one for this: `otp_integrations` rows are per store and
 * configured by the merchant, with the merchant's own credentials. A merchant who controls
 * the sending gateway can verify any number they like, including one that is not theirs,
 * which makes the flag worth nothing exactly where it has to be worth something. The code
 * therefore goes out from Store Syria's OWN Lynomia workspace -- the same sender, channel and
 * approved templates as every other platform notice, and a credential no merchant holds.
 *
 * THE TEMPLATE MUST BE UTILITY. The platform workspace refuses anything Meta does not
 * classify as UTILITY, which is the owner's Decision 2 and is not relaxed here: an operator
 * configures an approved UTILITY template with one body parameter for the code. Meta's own
 * AUTHENTICATION category would be refused by that gate; admitting it is an owner decision
 * and is recorded as one rather than taken here.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not write the candidate number anywhere a
 * reader would take for the merchant's contact details until it is proved, it does not touch
 * a previously verified number while an unproved one is in flight, and it never puts either
 * the number or the code in the audit trail.
 */
class MerchantContactVerification
{
    /** Between one request and the next, so Send is not a free way to ring somebody's phone. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(private readonly PlatformWorkspaceGateway $workspace)
    {
    }

    /**
     * Send a code to a number the merchant says is theirs.
     *
     * @throws LynomiaApiException when the candidate cannot be used, or the merchant is asking
     *                             again too soon. Both are the caller's to show; neither is a
     *                             failure of the integration.
     */
    public function request(User $merchant, Store $store, string $candidate): LynomiaContactVerification
    {
        $this->assertOwns($merchant, $store);

        $state = MerchantContact::classify($candidate);

        if ($state !== MerchantContact::UNVERIFIED) {
            // UNVERIFIED is what `classify` returns for a well-formed number: it says nothing
            // about whether anybody proved it, which is the question this method exists to put.
            throw new LynomiaApiException(LynomiaApiException::KIND_CONFLICT, match ($state) {
                MerchantContact::MISSING => __('Enter the mobile number you want to confirm.'),
                MerchantContact::PLACEHOLDER => __('That is a placeholder number, not a real one.'),
                default => __('That does not look like a mobile number that can receive messages.'),
            });
        }

        $digits = MerchantContact::digitsOf($candidate);
        $existing = LynomiaContactVerification::where('user_id', $merchant->id)->first();

        if ($existing !== null && $existing->created_at?->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_RATE_LIMITED,
                __('A code was just sent. Wait a moment before asking for another one.')
            );
        }

        $code = (string) random_int(100000, 999999);

        // updateOrCreate on the unique user_id: one challenge in flight, and asking again
        // replaces the last one rather than leaving two live codes for one number.
        $verification = LynomiaContactVerification::updateOrCreate(
            ['user_id' => (int) $merchant->id],
            [
                'store_id' => (int) $store->id,
                'candidate_phone' => $digits,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(LynomiaContactVerification::TTL_MINUTES),
                'attempts' => 0,
                'delivery_state' => null,
                'delivery_reason' => null,
            ]
        );
        // created_at is not touched by updateOrCreate on an existing row, and the cooldown is
        // measured from it, so a merchant could otherwise ask once a second for ever.
        $verification->forceFill(['created_at' => now()])->save();

        $this->deliver($verification, $merchant, $digits, $code);

        AuditLog::record('lynomia_contact_verification_requested', $merchant, [], [
            'store_id' => (int) $store->id,
            'delivery_state' => $verification->delivery_state,
            'delivery_reason' => $verification->delivery_reason,
            // Neither the number nor the code is recorded. The trail says a merchant was sent
            // a code and what became of the send; it is not a copy of their contact details.
        ]);

        return $verification->refresh();
    }

    /**
     * Take a code from the merchant and, if it is the one we sent, promote the number.
     *
     * @return bool true when the number is now verified
     *
     * @throws LynomiaApiException when there is nothing to answer, or the window has closed
     */
    public function confirm(User $merchant, string $presented): bool
    {
        $verification = LynomiaContactVerification::where('user_id', $merchant->id)->first();

        if ($verification === null) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                __('Ask for a code first.')
            );
        }

        if (! $verification->open()) {
            // Expired or spent. Deleted rather than left lying about, so the next request is a
            // clean one and an exhausted challenge cannot be ground down further.
            $verification->delete();

            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                __('That code is no longer valid. Ask for a new one.')
            );
        }

        // THE ATTEMPT IS SPENT BEFORE THE COMPARISON, not after. Counting only failures that
        // reach the end of the method makes every way out of it -- an exception, a timeout, a
        // deploy -- a free guess.
        $verification->increment('attempts');

        if (! Hash::check($presented, (string) $verification->code_hash)) {
            AuditLog::record('lynomia_contact_verification_failed', $merchant, [], [
                'store_id' => (int) $verification->store_id,
                'attempts' => (int) $verification->attempts,
                // Not the presented value: a wrong code is often a right code for somebody else.
            ]);

            return false;
        }

        DB::transaction(function () use ($merchant, $verification) {
            $merchant->forceFill([
                'mobile' => $verification->candidate_phone,
                'mobile_verified_at' => now(),
            ])->save();

            // One-time: the code cannot be replayed to re-verify a number the merchant has
            // since changed.
            $verification->delete();
        });

        AuditLog::record('lynomia_contact_verification_confirmed', $merchant, [], [
            'store_id' => (int) $verification->store_id,
        ]);

        return true;
    }

    /**
     * A NUMBER THAT CHANGED IS A NUMBER NOBODY HAS PROVED.
     *
     * Called from the ordinary profile editor, which still accepts a typed number as it always
     * has -- requirement is that the existing workflow keeps working, not that it stops. What
     * must not survive the edit is the CLAIM attached to the old value: without this, a
     * merchant verifies a number they hold and then quietly types another, and the flag says
     * somebody proved a number nobody has ever answered on.
     *
     * Compared on digits, so reformatting +963 900 111 222 as 963900111222 is not a change.
     */
    public function forgetIfChanged(User $merchant, ?string $newValue): void
    {
        if ($merchant->mobile_verified_at === null) {
            return;
        }

        if (MerchantContact::digitsOf((string) $newValue) === MerchantContact::digitsOf((string) $merchant->mobile)) {
            return;
        }

        $merchant->forceFill(['mobile_verified_at' => null])->save();
        LynomiaContactVerification::where('user_id', $merchant->id)->delete();

        AuditLog::record('lynomia_contact_verification_cleared', $merchant, [], [
            'reason' => 'number_changed',
        ]);
    }

    /**
     * Hand the code to the platform workspace, and record what became of the attempt.
     *
     * Best effort by design: a merchant whose code could not be sent needs to be told why --
     * most often that platform communications are not set up on this installation at all --
     * rather than shown a generic failure with a live challenge they can never answer.
     */
    private function deliver(LynomiaContactVerification $verification, User $merchant, string $digits, string $code): void
    {
        try {
            $this->workspace->sendTemplate(
                $digits,
                (string) ($merchant->name ?? 'Merchant'),
                (string) config('lynomia.platform_notice_templates.contact_verification', 'contact_verification'),
                (string) config('lynomia.platform_notice_language', 'en_US'),
                ['1' => $code]
            );

            $verification->forceFill(['delivery_state' => 'accepted', 'delivery_reason' => null])->save();
        } catch (LynomiaApiException $e) {
            $verification->forceFill([
                'delivery_state' => 'failed',
                'delivery_reason' => Str::limit('kind_' . $e->kind, 191, ''),
            ])->save();
        }
    }

    /** A merchant may only confirm their own number, on a store they own. */
    private function assertOwns(User $merchant, Store $store): void
    {
        if ((int) $store->created_by !== (int) $merchant->id) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_NOT_PERMISSIBLE,
                __('That store does not belong to you.')
            );
        }
    }
}
