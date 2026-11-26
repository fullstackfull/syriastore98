<?php

namespace Tests\Unit\Kyc;

use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Services\Kyc\KycAccessLog;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycDocumentStore;
use App\Services\Kyc\KycRenewal;
use App\Services\Kyc\KycReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Kyc\MakesKycFixtures;
use Tests\TestCase;

/**
 * KYC-002, THIRD CLAUSE — التجديد, RENEWAL. THE SYSTEM KNOWS WHAT HAS LAPSED.
 * =================================================================================================
 *
 * TWO PROPERTIES, AND THE SECOND IS THE ONE THAT MATTERS FOR SAFETY:
 *
 *  1. THE SWEEP MOVES EXPIRED RECORDS AND LOGS EACH MOVE, so a lapse is visible and auditable.
 *  2. **THE GATE DOES NOT WAIT FOR THE SWEEP.** `isCurrent()` reads the stored DATE, so a record
 *     whose expiry passed an hour ago is already not current whether or not the command has run.
 *     A missed cron run therefore cannot GRANT anything — the derived state is the pessimistic one.
 *     That is the opposite of the usual projection pattern in this codebase (`marketing_contact_states`
 *     is a projection the gate reads) and is deliberate: a projection the gate trusts is a
 *     projection whose staleness is an authorization bug.
 *
 * AND NOTHING ELSE HAPPENS ON A LAPSE. No suspension, no payment block, no notification. What a
 * lapse COSTS is an owner decision (`D-P6-46`); a sweep that invented a consequence would be a
 * business rule nobody approved, enforced by a cron job. `test_the_sweep_changes_nothing_but_the_status`
 * asserts that absence rather than leaving it to be assumed.
 */
class KycRenewalTest extends TestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: KycVerification, 3: KycDocument} */
    private function approvedDocument(?string $expiresOn): array
    {
        Storage::fake('kyc');

        $operator = $this->kycOperator();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA, KycVerification::COMPANY, $merchant);

        $document = KycDocumentStore::store(
            $record,
            $this->kycPngUpload(),
            'commercial_register',
            $merchant,
            ['expires_on' => $expiresOn]
        );

        /*
         * THE APPROVAL HAPPENS WHILE THE DOCUMENT IS STILL CURRENT, AND THE FIRST VERSION OF THIS
         * FIXTURE COULD NOT BUILD ITS OWN SCENARIO BECAUSE OF IT. Approving a verification whose
         * only document has ALREADY lapsed is refused by `missingKinds()` — correctly, because an
         * expired licence must not satisfy a requirement. So an "approved record that has since
         * lapsed" is built the way reality builds it: the decision is taken at a moment when the
         * paper was good, and then time passes.
         *
         * `Carbon::setTestNow()` moves the clock for the decision only and is reset immediately;
         * `reviewed_at` is then genuinely in the past, which is what such a record looks like.
         */
        $moment = $expiresOn === null ? null : Carbon::parse($expiresOn)->subDay();

        if ($moment !== null && $moment->isPast()) {
            Carbon::setTestNow($moment);
        }

        try {
            KycReview::decideDocument($document, $operator, KycReview::APPROVE, null, $expiresOn);
            KycReview::decideVerification($record->fresh(), $operator, KycReview::APPROVE, null, $expiresOn);
        } finally {
            Carbon::setTestNow();
        }

        return [$merchant, $store, $record->fresh(), $document->fresh()];
    }

    public function test_an_expired_document_is_not_current_before_any_sweep_runs(): void
    {
        [, , $record, $document] = $this->approvedDocument(Carbon::now()->subDay()->toDateString());

        $this->assertSame(KycDocument::APPROVED, $document->status, 'the sweep has not run');
        $this->assertFalse($document->isCurrent(), 'the stored date decides, not the cron job');
        $this->assertFalse($record->isCurrent());
    }

    public function test_a_document_with_no_expiry_is_current_when_approved(): void
    {
        [, , $record, $document] = $this->approvedDocument(null);

        $this->assertTrue($document->isCurrent());
        $this->assertTrue($record->isCurrent());
    }

    public function test_a_future_expiry_is_current_and_todays_expiry_is_still_current(): void
    {
        [, , , $future] = $this->approvedDocument(Carbon::now()->addMonth()->toDateString());
        $this->assertTrue($future->isCurrent());

        [, , , $today] = $this->approvedDocument(Carbon::now()->toDateString());
        $this->assertTrue(
            $today->isCurrent(),
            'a document that expires TODAY is good until the end of today — an off-by-one here is '
            . 'the 18-site date-boundary bug this project already found in ReportController'
        );
    }

    public function test_the_sweep_marks_expired_records_and_logs_each_one(): void
    {
        [, $store, $record, $document] = $this->approvedDocument(Carbon::now()->subDays(3)->toDateString());

        $counts = KycRenewal::sweep();

        $this->assertSame(['documents' => 1, 'verifications' => 1], $counts);

        $this->assertSame(
            KycDocument::EXPIRED,
            DB::table('kyc_documents')->where('id', $document->id)->value('status')
        );
        $this->assertSame(
            KycVerification::EXPIRED,
            DB::table('kyc_verifications')->where('id', $record->id)->value('status')
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')->where('action', KycAccessLog::EXPIRED)->count(),
            'a lapse is an auditable event'
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('action', KycAccessLog::VERIFICATION_EXPIRED)->count()
        );
        $this->assertSame(
            (int) $store->id,
            (int) DB::table('audit_logs')->where('action', KycAccessLog::EXPIRED)->value('store_id'),
            'filed against the merchant whose document lapsed'
        );
    }

    public function test_the_sweep_is_idempotent(): void
    {
        $this->approvedDocument(Carbon::now()->subDays(3)->toDateString());

        KycRenewal::sweep();

        $this->assertSame(
            ['documents' => 0, 'verifications' => 0],
            KycRenewal::sweep(),
            'a second pass must not re-expire what it already expired, or the trail fills with '
            . 'one row per cron tick'
        );
    }

    public function test_the_sweep_leaves_unexpired_and_already_terminal_records_alone(): void
    {
        [, , $future] = $this->approvedDocument(Carbon::now()->addMonth()->toDateString());
        [, , $none] = $this->approvedDocument(null);

        $this->assertSame(['documents' => 0, 'verifications' => 0], KycRenewal::sweep());

        $this->assertSame(
            KycVerification::APPROVED,
            DB::table('kyc_verifications')->where('id', $future->id)->value('status')
        );
        $this->assertSame(
            KycVerification::APPROVED,
            DB::table('kyc_verifications')->where('id', $none->id)->value('status')
        );
    }

    /**
     * THE SWEEP CHANGES THE STATUS AND NOTHING ELSE. See the class docblock: what a lapse costs is
     * an owner decision, so a consequence invented here would be an unapproved business rule.
     */
    public function test_the_sweep_changes_nothing_but_the_status(): void
    {
        [, $store, , ] = $this->approvedDocument(Carbon::now()->subDays(3)->toDateString());

        $storeBefore = (array) DB::table('stores')->where('id', $store->id)->first();
        $documentBefore = (array) DB::table('kyc_documents')->first();
        $filesBefore = $this->kycStoredFiles();

        KycRenewal::sweep();

        $this->assertEquals(
            $storeBefore,
            (array) DB::table('stores')->where('id', $store->id)->first(),
            'a lapse must not suspend, flag or otherwise touch the store — that is an owner decision'
        );

        $documentAfter = (array) DB::table('kyc_documents')->first();

        foreach ($documentBefore as $column => $value) {
            if (in_array($column, ['status', 'updated_at'], true)) {
                continue;
            }

            $this->assertSame($value, $documentAfter[$column], "the sweep changed {$column}");
        }

        $this->assertSame($filesBefore, $this->kycStoredFiles(), 'a lapse must never delete the file');
    }

    public function test_due_for_renewal_reports_this_stores_documents_only(): void
    {
        [, $store, , $document] = $this->approvedDocument(Carbon::now()->addDays(10)->toDateString());
        [, $otherStore, , ] = $this->approvedDocument(Carbon::now()->addDays(10)->toDateString());

        $due = KycRenewal::dueForRenewal((int) $store->id);

        $this->assertCount(1, $due);
        $this->assertSame((int) $document->id, (int) $due->first()->id);

        $otherDue = KycRenewal::dueForRenewal((int) $otherStore->id);
        $this->assertCount(1, $otherDue);
        $this->assertNotSame((int) $document->id, (int) $otherDue->first()->id);
    }

    public function test_due_for_renewal_respects_the_notice_window(): void
    {
        [, $store, , ] = $this->approvedDocument(Carbon::now()->addDays(90)->toDateString());

        config(['kyc.renewal_notice_days' => 30]);
        $this->assertCount(0, KycRenewal::dueForRenewal((int) $store->id));

        config(['kyc.renewal_notice_days' => 120]);
        $this->assertCount(1, KycRenewal::dueForRenewal((int) $store->id));
    }

    public function test_due_for_renewal_can_be_narrowed_to_one_authority(): void
    {
        [, $store, , ] = $this->approvedDocument(Carbon::now()->addDays(10)->toDateString());

        $this->assertCount(
            1,
            KycRenewal::dueForRenewal((int) $store->id, KycAuthority::STORE_SYRIA)
        );
        $this->assertCount(
            0,
            KycRenewal::dueForRenewal((int) $store->id, KycAuthority::PAYMENT_PROVIDER),
            'KYC-003: one authority\'s renewal is not another\'s'
        );
    }

    public function test_due_for_renewal_refuses_a_store_that_is_not_a_store(): void
    {
        $this->approvedDocument(Carbon::now()->addDays(10)->toDateString());

        $this->assertCount(0, KycRenewal::dueForRenewal(0));
        $this->assertCount(0, KycRenewal::dueForRenewal(-1));
    }

    /**
     * `php artisan kyc:expire` IS REGISTERED AND DOES THE WORK.
     *
     * Driven rather than assumed: Laravel 11 discovers commands in `app/Console/Commands`, and a
     * class that is not discovered, or whose signature collides, is invisible until somebody runs
     * it on a server. The output figure is asserted too, so a command that ran and swept nothing
     * cannot report success.
     */
    public function test_the_console_command_runs_the_sweep(): void
    {
        $this->approvedDocument(Carbon::now()->subDays(3)->toDateString());

        $this->artisan('kyc:expire')
            ->expectsOutputToContain('1 document(s) and 1 verification record(s) marked expired.')
            ->assertExitCode(0);

        $this->assertSame(
            KycDocument::EXPIRED,
            DB::table('kyc_documents')->value('status')
        );

        // A second run reports nothing, because the sweep is idempotent.
        $this->artisan('kyc:expire')
            ->expectsOutputToContain('0 document(s) and 0 verification record(s) marked expired.')
            ->assertExitCode(0);
    }

    /** The grace period is configuration and is honoured in both directions. */
    public function test_the_grace_period_is_honoured(): void
    {
        [, , , $document] = $this->approvedDocument(Carbon::now()->subDays(2)->toDateString());

        config(['kyc.expiry_grace_days' => 0]);
        $this->assertFalse($document->isCurrent());

        config(['kyc.expiry_grace_days' => 5]);
        $this->assertTrue(
            $document->isCurrent(),
            'a configured grace period must apply to the gate and to the sweep alike'
        );

        $this->assertSame(['documents' => 0, 'verifications' => 0], KycRenewal::sweep());
    }
}
