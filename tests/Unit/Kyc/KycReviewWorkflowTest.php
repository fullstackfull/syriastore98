<?php

namespace Tests\Unit\Kyc;

use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Services\Kyc\KycAccessLog;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycDocumentStore;
use App\Services\Kyc\KycRefusal;
use App\Services\Kyc\KycReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Kyc\MakesKycFixtures;
use Tests\TestCase;

/**
 * KYC-002, SECOND CLAUSE — المراجعات, THE REVIEWS, AND WHAT A DOCUMENT UPLOAD WILL AND WILL NOT TAKE.
 * =================================================================================================
 *
 * THE MOST IMPORTANT TEST IN THIS FILE IS THE FIRST ONE: with no owner decision about which
 * documents are required, **every upload is refused**. That is not a stub — identity papers are
 * sensitive personal data under this project's standing safety boundary, and a mechanism that
 * collected them before anyone decided which were needed would be collecting data nobody asked for.
 * `config/kyc.php` ships `document_kinds` empty for every subject type and authority.
 *
 * THE REST ASSERTS THAT THE MECHANISM ENFORCES WHATEVER THE OWNER CONFIGURES AND INVENTS NOTHING:
 * a configured kind with no approved document blocks approval; an APPROVED BUT EXPIRED document does
 * not satisfy a requirement; a replacement supersedes rather than deletes; and the platform's
 * hardened uploader rule decides what a file may be.
 */
class KycReviewWorkflowTest extends TestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;

    /**
     * THE SHIPPED DEFAULT COLLECTS NOTHING. Driven against the real `config/kyc.php` rather than a
     * value the test sets, because the claim is about what the repository ships.
     */
    public function test_with_no_configured_kinds_every_upload_is_refused(): void
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();

        foreach (KycVerification::SUBJECT_TYPES as $subjectType) {
            foreach (KycAuthority::ALL as $authority) {
                $this->assertSame(
                    [],
                    KycAuthority::documentKinds($subjectType, $authority),
                    "config/kyc.php ships a document kind for {$subjectType}/{$authority} — which "
                    . 'authorises collecting a document the owner has not asked for'
                );
            }
        }

        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        try {
            KycDocumentStore::store($record, $this->kycPngUpload(), 'passport', $merchant);
            $this->fail('a document was collected with no owner decision about which documents to collect');
        } catch (KycRefusal $e) {
            $this->assertSame('no_kinds', $e->reason);
        }

        $this->assertSame([], $this->kycStoredFiles(), 'nothing may reach the disk');
        $this->assertSame(0, DB::table('kyc_documents')->count());
    }

    public function test_a_kind_outside_the_configured_list_is_refused(): void
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        try {
            KycDocumentStore::store($record, $this->kycPngUpload(), 'passport', $merchant);
            $this->fail('an unconfigured kind was accepted');
        } catch (KycRefusal $e) {
            $this->assertSame('unknown_kind', $e->reason);
        }

        $this->assertSame([], $this->kycStoredFiles());
    }

    /**
     * THE PLATFORM'S HARDENED UPLOADER RULE IS WHAT DECIDES WHAT A FILE MAY BE, and it is reached
     * with the CLIENT'S OWN NAME under judgement — so `shell.php.png` (an extension a web server
     * maps to a handler INSIDE the name), an extension outside the accepted list, and a file whose
     * signature is not what its name claims are all refused by `PlatformUploadPolicy` rather than by
     * a second rule written here.
     *
     * @dataProvider unacceptableFiles
     */
    public function test_an_unacceptable_file_is_refused_and_nothing_is_stored(string $label, callable $make): void
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        try {
            KycDocumentStore::store($record, $make(), 'commercial_register', $merchant);
            $this->fail($label . ' was accepted as an identity document');
        } catch (KycRefusal $e) {
            $this->assertSame('file', $e->reason, $label);
        }

        $this->assertSame([], $this->kycStoredFiles(), $label . ' reached the disk');
        $this->assertSame(0, DB::table('kyc_documents')->count(), $label . ' created a row');
    }

    public static function unacceptableFiles(): array
    {
        return [
            'a handler extension inside the name' => [
                'shell.php.png',
                static fn (): UploadedFile => UploadedFile::fake()->image('shell.phtml.png', 20, 20),
            ],
            'an extension outside the accepted list' => [
                'an SVG',
                static fn (): UploadedFile => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
            ],
            'a script-capable extension' => [
                'an HTML file',
                static fn (): UploadedFile => UploadedFile::fake()->create('report.html', 4, 'text/html'),
            ],
            'a file whose signature is not its name' => [
                'a text file named .png',
                static fn (): UploadedFile => UploadedFile::fake()->create('scan.png', 4, 'text/plain'),
            ],
        ];
    }

    public function test_an_oversized_file_is_refused(): void
    {
        Storage::fake('kyc');
        config(['kyc.max_kilobytes' => 1]);

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        try {
            KycDocumentStore::store(
                $record,
                UploadedFile::fake()->image('big.png', 400, 400)->size(4096),
                'commercial_register',
                $merchant
            );
            $this->fail('an oversized document was accepted');
        } catch (KycRefusal $e) {
            $this->assertSame('file', $e->reason);
        }

        $this->assertSame([], $this->kycStoredFiles());
    }

    public function test_a_configured_kind_with_no_approved_document_blocks_approval(): void
    {
        Storage::fake('kyc');

        $operator = $this->kycOperator();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register', 'tax_card']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $one = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);
        KycReview::decideDocument($one, $operator, KycReview::APPROVE);

        $this->assertSame(['tax_card'], KycReview::missingKinds($record->fresh()));

        try {
            KycReview::decideVerification($record->fresh(), $operator, KycReview::APPROVE);
            $this->fail('a record was approved with a required document outstanding');
        } catch (KycRefusal $e) {
            $this->assertSame('missing_documents', $e->reason);
        }

        $two = KycDocumentStore::store($record, $this->kycPngUpload(), 'tax_card', $merchant);
        KycReview::decideDocument($two, $operator, KycReview::APPROVE);

        $this->assertSame([], KycReview::missingKinds($record->fresh()));

        KycReview::decideVerification($record->fresh(), $operator, KycReview::APPROVE);

        $this->assertSame(
            KycVerification::APPROVED,
            DB::table('kyc_verifications')->where('id', $record->id)->value('status')
        );
    }

    /**
     * AN APPROVED BUT EXPIRED DOCUMENT DOES NOT SATISFY A REQUIREMENT. Reading the status alone
     * would let a lapsed licence hold a verification open — the pessimistic read, everywhere.
     */
    public function test_an_expired_document_does_not_satisfy_a_requirement(): void
    {
        Storage::fake('kyc');

        $operator = $this->kycOperator();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        $yesterday = Carbon::now()->subDay()->toDateString();

        Carbon::setTestNow(Carbon::now()->subDays(2));

        try {
            KycReview::decideDocument($document, $operator, KycReview::APPROVE, null, $yesterday);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            KycDocument::APPROVED,
            DB::table('kyc_documents')->where('id', $document->id)->value('status')
        );
        $this->assertSame(
            ['commercial_register'],
            KycReview::missingKinds($record->fresh()),
            'an approved document that has lapsed still satisfied a requirement'
        );
    }

    /** A replacement SUPERSEDES: the old row stays, the old file stays, the trail stays readable. */
    public function test_a_replacement_supersedes_and_deletes_nothing(): void
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $first = KycDocumentStore::store($record, $this->kycPngUpload('old.png'), 'commercial_register', $merchant);
        $second = KycDocumentStore::store($record, $this->kycPngUpload('new.png'), 'commercial_register', $merchant);

        $this->assertSame(
            KycDocument::SUPERSEDED,
            DB::table('kyc_documents')->where('id', $first->id)->value('status')
        );
        $this->assertSame(
            KycDocument::PENDING,
            DB::table('kyc_documents')->where('id', $second->id)->value('status')
        );

        $this->assertCount(
            2,
            $this->kycStoredFiles(),
            'a superseded document\'s file must stay: the trail refers to it and a reviewer must '
            . 'still be able to see what they approved'
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')->where('action', KycAccessLog::SUPERSEDED)->count()
        );
    }

    /** A superseded document can no longer be decided. */
    public function test_a_superseded_document_cannot_be_reviewed(): void
    {
        Storage::fake('kyc');

        $operator = $this->kycOperator();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $first = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);
        KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        try {
            KycReview::decideDocument($first->fresh(), $operator, KycReview::APPROVE);
            $this->fail('a superseded document was reviewed');
        } catch (KycRefusal $e) {
            $this->assertSame('terminal', $e->reason);
        }
    }

    public function test_a_rejection_records_the_reviewer_the_time_and_the_note(): void
    {
        Storage::fake('kyc');

        $operator = $this->kycOperator();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        KycReview::decideDocument($document, $operator, KycReview::REJECT, str_repeat('x', 1500));

        $row = DB::table('kyc_documents')->where('id', $document->id)->first();

        $this->assertSame(KycDocument::REJECTED, $row->status);
        $this->assertSame((int) $operator->id, (int) $row->reviewed_by);
        $this->assertNotNull($row->reviewed_at);
        $this->assertSame(1000, strlen((string) $row->review_note), 'the note is cut to the column');

        $this->assertSame(
            1,
            DB::table('audit_logs')->where('action', KycAccessLog::REJECTED)->count()
        );
    }

    public function test_a_merchant_may_submit_their_own_record_and_a_stranger_may_not(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        [$stranger] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        try {
            KycReview::submit($record, $stranger);
            $this->fail('a stranger submitted another tenant\'s record');
        } catch (KycRefusal $e) {
            $this->assertSame('not_this_store', $e->reason);
        }

        KycReview::submit($record->fresh(), $merchant);

        $row = DB::table('kyc_verifications')->where('id', $record->id)->first();

        $this->assertSame(KycVerification::SUBMITTED, $row->status);
        $this->assertNotNull($row->submitted_at);
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('action', KycAccessLog::VERIFICATION_SUBMITTED)->count()
        );
    }

    public function test_an_approved_record_cannot_be_resubmitted(): void
    {
        $operator = $this->kycOperator();
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        KycReview::decideVerification($record, $operator, KycReview::APPROVE);

        $this->expectException(KycRefusal::class);

        KycReview::submit($record->fresh(), $merchant);
    }

    public function test_a_decision_that_is_not_a_decision_is_refused(): void
    {
        $operator = $this->kycOperator();
        [, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        try {
            KycReview::decideVerification($record, $operator, 'maybe');
            $this->fail('an unknown decision was accepted');
        } catch (KycRefusal $e) {
            $this->assertSame('decision', $e->reason);
        }
    }
}
