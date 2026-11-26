<?php

namespace Tests\Unit\Kyc;

use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Services\Kyc\KycAccessLog;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycDocumentStore;
use App\Services\Kyc\KycRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\Kyc\MakesKycFixtures;
use Tests\TestCase;

/**
 * KYC-002, FOURTH CLAUSE — سجلات الوصول. WHO LOOKED AT A MERCHANT'S IDENTITY PAPERS, AND WHEN.
 * =================================================================================================
 *
 * THE CLAUSE MOST LIKELY TO BE MISSING AND THE ONE MOST WORTH HAVING. An identity-document store
 * without an access log is a store nobody can answer questions about: a merchant cannot find out
 * who opened their passport scan, and the platform cannot show that nobody did.
 *
 * THE STRUCTURAL CLAIM UNDER TEST is not "the code writes a log line" — it is that **THERE IS NO WAY
 * TO READ THE BYTES THAT DOES NOT WRITE THE ROW.** `KycDocumentStore::readForActor()` is the only
 * reader and it records before it returns; `KycPrivateStorageTest`'s census is what stops a second
 * reader existing. This file drives the row: its action, its actor, its store, and what it does NOT
 * contain.
 *
 * THE OPERATOR ATTRIBUTION IS THE ASSERTION THAT MATTERS MOST, and it is the one a fresh table would
 * have got wrong. `getCurrentStore()` FABRICATES store 1 for the platform operator
 * (`helper.php`: `if ($user->type == 'super admin') { return 1; }`), so an audit row built from the
 * session would file every operator action under store 1 whoever it was taken against — *"auditing
 * merchant Y's history MISSES every operator action taken against Y"*, in
 * `AuditAttribution`'s own words. An access log for identity documents is almost entirely operator
 * reads of other people's papers, so this is the whole value of the trail.
 */
class KycAccessLogTest extends TestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;

    private function storedDocument(): array
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA, KycVerification::COMPANY, $merchant);

        $document = KycDocumentStore::store(
            $record,
            $this->kycPngUpload(),
            'commercial_register',
            $merchant
        );

        return [$merchant, $store, $record, $document];
    }

    public function test_storing_a_document_writes_one_upload_row_against_the_merchants_store(): void
    {
        [$merchant, $store, , $document] = $this->storedDocument();

        $rows = DB::table('audit_logs')->where('action', KycAccessLog::UPLOADED)->get();

        $this->assertCount(1, $rows);
        $this->assertSame((int) $merchant->id, (int) $rows[0]->user_id);
        $this->assertSame((int) $store->id, (int) $rows[0]->store_id);
        $this->assertSame(KycDocument::class, $rows[0]->subject_type);
        $this->assertSame((int) $document->id, (int) $rows[0]->subject_id);
    }

    public function test_reading_the_bytes_writes_exactly_one_read_row(): void
    {
        [$merchant, $store, , $document] = $this->storedDocument();

        $bytes = KycDocumentStore::readForActor($document, $merchant);

        $this->assertNotSame('', $bytes);

        $rows = DB::table('audit_logs')->where('action', KycAccessLog::READ)->get();

        $this->assertCount(1, $rows, 'one read, one row');
        $this->assertSame((int) $merchant->id, (int) $rows[0]->user_id);
        $this->assertSame((int) $store->id, (int) $rows[0]->store_id);

        KycDocumentStore::readForActor($document, $merchant);

        $this->assertSame(
            2,
            DB::table('audit_logs')->where('action', KycAccessLog::READ)->count(),
            'two reads, two rows — the trail counts accesses, not documents'
        );
    }

    /**
     * THE OPERATOR'S READ IS FILED AGAINST THE MERCHANT'S STORE, NOT STORE 1. See the class
     * docblock: this is the defect a fresh audit table would have reproduced.
     */
    public function test_an_operators_read_is_filed_against_the_merchants_store(): void
    {
        [, $store, , $document] = $this->storedDocument();
        $operator = $this->kycOperator();

        // The operator's own session store, which getCurrentStore() would have substituted.
        $operator->current_store = 1;
        $operator->save();

        KycDocumentStore::readForActor($document, $operator);

        $row = DB::table('audit_logs')->where('action', KycAccessLog::READ)->first();

        $this->assertSame((int) $operator->id, (int) $row->user_id);
        $this->assertSame(
            (int) $store->id,
            (int) $row->store_id,
            'an operator reading a merchant\'s papers must be filed against THAT merchant, or the '
            . 'merchant\'s own trail is missing exactly the accesses they cannot see for themselves'
        );
    }

    /** The trail never carries the secret it logs access to. */
    public function test_the_trail_does_not_contain_the_storage_name(): void
    {
        [$merchant, , , $document] = $this->storedDocument();

        KycDocumentStore::readForActor($document, $merchant);

        $leaf = basename((string) $document->storage_name);

        foreach (DB::table('audit_logs')->get() as $row) {
            $encoded = json_encode($row);

            $this->assertStringNotContainsString($leaf, (string) $encoded);
            $this->assertStringNotContainsString((string) $document->storage_name, (string) $encoded);
        }
    }

    public function test_the_writer_refuses_a_storage_name_detail(): void
    {
        [, , , $document] = $this->storedDocument();

        $this->expectException(InvalidArgumentException::class);

        KycAccessLog::record(KycAccessLog::READ, $document, ['storage_name' => 'anything']);
    }

    public function test_the_writer_refuses_an_action_outside_the_vocabulary(): void
    {
        [, , , $document] = $this->storedDocument();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown KYC access-log action');

        KycAccessLog::record('kyc.docuemnt.read', $document);
    }

    public function test_the_writer_refuses_a_subject_that_is_not_a_kyc_record(): void
    {
        [$merchant] = $this->kycMerchantWithStore();

        $this->expectException(InvalidArgumentException::class);

        KycAccessLog::record(KycAccessLog::READ, $merchant);
    }

    /**
     * A MISSING FILE IS A LOGGED REFUSAL, NOT A SILENT EMPTY — the event a trail most needs to show.
     */
    public function test_a_document_whose_file_has_gone_logs_a_refusal(): void
    {
        [$merchant, , , $document] = $this->storedDocument();

        Storage::disk('kyc')->delete((string) $document->storage_name);

        try {
            KycDocumentStore::readForActor($document, $merchant);
            $this->fail('a document with no file was read');
        } catch (KycRefusal $e) {
            $this->assertSame('missing', $e->reason);
        }

        $row = DB::table('audit_logs')->where('action', KycAccessLog::REFUSED)->first();

        $this->assertNotNull($row);
        $this->assertSame('missing', json_decode((string) $row->new_values, true)['reason']);
        $this->assertSame(
            0,
            DB::table('audit_logs')->where('action', KycAccessLog::READ)->count(),
            'a refused read must not also be recorded as a read'
        );
    }

    /**
     * THE MERCHANT'S READER SHOWS THEIR OWN DOCUMENT'S TRAIL AND NOTHING ELSE.
     *
     * `AuditAttribution`'s docblock warns that the historical rows filed under store 1 become a
     * cross-tenant disclosure *"the day a reader is added"*. This reader is that day, so it is
     * predicated on the subject AND the store, and this test plants a store-1 row of another kind
     * to prove the reader is not a general audit window.
     */
    public function test_the_reader_is_scoped_to_one_document_and_one_store(): void
    {
        [$merchant, $store, , $document] = $this->storedDocument();
        KycDocumentStore::readForActor($document, $merchant);

        // Another tenant's document, with its own trail.
        Storage::fake('kyc');
        [$otherMerchant, $otherStore] = $this->kycMerchantWithStore();
        $otherRecord = $this->kycRecord($otherStore, KycAuthority::STORE_SYRIA);
        $otherDocument = KycDocumentStore::store(
            $otherRecord,
            $this->kycPngUpload(),
            'commercial_register',
            $otherMerchant
        );
        KycDocumentStore::readForActor($otherDocument, $otherMerchant);

        // A pre-existing audit row of a different kind, filed under this store.
        AuditLog::create([
            'user_id' => $merchant->id,
            'store_id' => $store->id,
            'action' => 'order.updated',
            'subject_type' => \App\Models\Order::class,
            'subject_id' => 99,
        ]);

        /*
         * A MIS-ATTRIBUTED ROW ABOUT THIS DOCUMENT, which is what the `store_id` predicate is
         * actually for. `AuditAttribution`'s docblock records that every operator action used to be
         * filed under store 1 whoever it concerned, and that those historical rows become a
         * cross-tenant disclosure *"the day a reader is added"*. This is that row: it names THIS
         * document and ANOTHER store, so a reader predicated only on the subject would show it.
         * Planted raw, because the writer would attribute it correctly.
         */
        DB::table('audit_logs')->insert([
            'user_id' => $otherMerchant->id,
            'store_id' => $otherStore->id,
            'action' => KycAccessLog::READ,
            'subject_type' => KycDocument::class,
            'subject_id' => $document->id,
        ]);

        $rows = \App\Services\Kyc\KycAccessLog::forDocument($document)->get();

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame(KycDocument::class, $row->subject_type);
            $this->assertSame((int) $document->id, (int) $row->subject_id);
            $this->assertSame((int) $store->id, (int) $row->store_id);
        }

        $this->assertNotContains(
            (int) $otherDocument->id,
            $rows->pluck('subject_id')->map(static fn ($v): int => (int) $v)->all(),
            'another tenant\'s document appeared in this merchant\'s access log'
        );

        $this->assertNotContains(
            (int) $otherStore->id,
            $rows->pluck('store_id')->map(static fn ($v): int => (int) $v)->all(),
            'a row about this document that was FILED under another store was shown — the '
            . 'store_id predicate is what stops a mis-attributed row reaching a merchant\'s screen'
        );
    }

    /** The verification-level reader covers the record's own rows and its documents'. */
    public function test_the_verification_reader_covers_the_record_and_its_documents(): void
    {
        [$merchant, , $record, $document] = $this->storedDocument();

        KycDocumentStore::readForActor($document, $merchant);
        \App\Services\Kyc\KycReview::submit($record, $merchant);

        $actions = \App\Services\Kyc\KycAccessLog::forVerification($record)->pluck('action')->all();

        $this->assertContains(KycAccessLog::UPLOADED, $actions);
        $this->assertContains(KycAccessLog::READ, $actions);
        $this->assertContains(KycAccessLog::VERIFICATION_SUBMITTED, $actions);
    }

    /**
     * THE ACTOR LABEL NEVER NAMES ANOTHER TENANT'S USER ON A MERCHANT'S SCREEN.
     *
     * The defect this closes was in the first version of `kyc/access-log.blade.php`: it printed
     * `$actor->name` for any non-operator actor, and attribution files a row against the SUBJECT's
     * store — so a stranger's refused attempt appeared on the victim's screen with the stranger's
     * full name. The merchant must still see the attempt; the stranger's identity is the platform's
     * to act on, not this merchant's to read.
     */
    public function test_the_actor_label_names_only_people_this_merchant_already_knows(): void
    {
        [$merchant, $store, , $document] = $this->storedDocument();
        $staff = $this->kycStaff($merchant, $store);
        $operator = $this->kycOperator();
        [$stranger] = $this->kycMerchantWithStore();

        $storeId = (int) $store->id;

        $this->assertSame($merchant->name, KycAccessLog::actorLabel($merchant->id, $storeId));
        $this->assertSame($staff->name, KycAccessLog::actorLabel($staff->id, $storeId));

        $this->assertSame(
            'Store Syria',
            KycAccessLog::actorLabel($operator->id, $storeId),
            'the operator is the platform, never a named account'
        );

        $this->assertSame(
            'Another account',
            KycAccessLog::actorLabel($stranger->id, $storeId),
            'another tenant\'s user must not be named on this merchant\'s screen'
        );
        $this->assertStringNotContainsString(
            (string) $stranger->name,
            KycAccessLog::actorLabel($stranger->id, $storeId)
        );

        foreach ([null, 0, -1, 999999] as $nobody) {
            $this->assertSame('The system', KycAccessLog::actorLabel($nobody, $storeId));
        }
    }

    /** And the screen really uses it: a stranger's refusal shows the event, not the name. */
    public function test_a_strangers_refusal_appears_without_their_name(): void
    {
        [$merchant, $store, , $document] = $this->storedDocument();
        [$stranger] = $this->kycMerchantWithStore();

        KycDocumentStore::refuse($document, 'not_this_store', $stranger);

        $entries = KycAccessLog::forDocument($document)->get();

        $this->assertContains(
            KycAccessLog::REFUSED,
            $entries->pluck('action')->all(),
            'the merchant must be able to see that somebody asked'
        );

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString(
                (string) $stranger->name,
                KycAccessLog::actorLabel($entry->user_id, (int) $store->id)
            );
        }
    }

    /**
     * EVERY ACTION IN THE VOCABULARY FITS THE COLUMN. `audit_logs.action` is `varchar(100)`; a
     * longer name would be silently truncated on MySQL in a non-strict mode and would then never
     * match a query. Cheap to assert, impossible to notice otherwise.
     */
    public function test_every_action_fits_the_audit_column(): void
    {
        foreach (KycAccessLog::ACTIONS as $action) {
            $this->assertLessThanOrEqual(100, strlen($action), $action . ' is too long for audit_logs.action');
            $this->assertStringStartsWith('kyc.', $action, 'every KYC action is queryable by one prefix');
        }
    }
}
