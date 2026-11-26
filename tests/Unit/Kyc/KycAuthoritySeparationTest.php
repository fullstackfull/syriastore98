<?php

namespace Tests\Unit\Kyc;

use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycRefusal;
use App\Services\Kyc\KycReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Support\Kyc\MakesKycFixtures;
use Tests\TestCase;

/**
 * KYC-003 — فصل توثيق Store Syria عن توثيق مزودي الدفع والتراخيص الحكومية.
 * =================================================================================================
 *
 * *"Separate Store Syria's verification from payment-provider and government-licence verification."*
 *
 * THE DEFECT THIS ASSERTS THE ABSENCE OF is the easy implementation: one `is_verified` flag. A
 * single flag cannot say WHO verified WHAT, so a merchant approved by a payment provider's own KYC
 * would read as verified by Store Syria, and a store holding a government trade licence would read
 * as verified for payments. The sentence forbids exactly that conflation.
 *
 * SO THE TEST IS NOT "an authority column exists" — a column proves nothing. It is: approving ONE
 * authority leaves the other two untouched, in ALL SIX ORDERED PAIRS, measured on the row rather
 * than on the model instance the test just wrote.
 *
 * AND THE ROWS ARE READ WITH `DB::table()`. Neither KYC model is `Cachable` — so this is belt, not
 * braces — but CLAUDE.md records three measured false readings where an Eloquent read served the
 * pre-write row, and "it is approved" / "it is not" is precisely the assertion that photographs a
 * cache instead of a table.
 */
class KycAuthoritySeparationTest extends TestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;

    public function test_the_three_authorities_are_the_three_the_plan_names(): void
    {
        $this->assertSame(
            ['store_syria', 'payment_provider', 'government_licence'],
            KycAuthority::ALL,
            'KYC-003 names Store Syria, payment providers and government licences as three things '
            . 'to be separated FROM EACH OTHER. Reading it as "Store Syria versus everyone else" '
            . 're-conflates the two external ones.'
        );
    }

    /**
     * THE CENTRAL INVARIANT. Six ordered pairs, each asserted on the table.
     */
    public function test_approving_one_authority_leaves_the_others_unapproved(): void
    {
        $operator = $this->kycOperator();
        [, $store] = $this->kycMerchantWithStore();

        foreach (KycAuthority::ALL as $approved) {
            foreach (KycAuthority::ALL as $other) {
                if ($approved === $other) {
                    continue;
                }

                DB::table('kyc_verifications')->delete();

                $approvedRecord = $this->kycRecord($store, $approved);
                $otherRecord = $this->kycRecord($store, $other);

                KycReview::decideVerification($approvedRecord, $operator, KycReview::APPROVE);

                $this->assertSame(
                    KycVerification::APPROVED,
                    DB::table('kyc_verifications')->where('id', $approvedRecord->id)->value('status'),
                    "approving {$approved} did not take"
                );

                $this->assertSame(
                    KycVerification::UNSUBMITTED,
                    DB::table('kyc_verifications')->where('id', $otherRecord->id)->value('status'),
                    "approving {$approved} also approved {$other} — the conflation KYC-003 forbids"
                );
            }
        }
    }

    /**
     * ONE RECORD PER STORE PER AUTHORITY, AT THE ENGINE. The model could be bypassed; the unique
     * key cannot. Asserted by attempting the second insert raw, so the model's own guard is not
     * what is being measured.
     */
    public function test_a_store_cannot_hold_two_records_for_one_authority(): void
    {
        [, $store] = $this->kycMerchantWithStore();

        $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('kyc_verifications')->insert([
            'store_id' => $store->id,
            'authority' => KycAuthority::STORE_SYRIA,
            'subject_type' => KycVerification::COMPANY,
            'status' => KycVerification::UNSUBMITTED,
        ]);
    }

    /**
     * NO SINGLE COLUMN ANSWERS "IS THIS STORE VERIFIED". The shortcut KYC-003 forbids would show up
     * as a boolean on `stores`, so its absence is asserted rather than assumed — and the assertion
     * is scoped to the names a shortcut would really use rather than to a grep of the whole schema.
     */
    public function test_no_authority_free_verification_flag_was_added_to_stores(): void
    {
        foreach (['is_verified', 'verified', 'kyc_verified', 'verification_status', 'verified_at'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('stores', $column),
                "stores.{$column} would answer \"is this store verified\" without naming an "
                . 'authority, which is the conflation KYC-003 forbids. Verification lives in '
                . 'kyc_verifications, keyed (store_id, authority).'
            );
        }
    }

    /** A document cannot be filed under one authority and carried by a record of another. */
    public function test_a_document_cannot_disagree_with_its_record_about_the_authority(): void
    {
        [, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $document = new KycDocument();
        $document->kyc_verification_id = $record->id;
        $document->store_id = $store->id;
        $document->authority = KycAuthority::PAYMENT_PROVIDER;      // the lie
        $document->document_kind = 'passport';
        $document->disk = 'kyc';
        $document->storage_name = $store->id . '/payment_provider/' . bin2hex(random_bytes(32));
        $document->original_name = 'p.png';
        $document->mime = 'image/png';
        $document->size_bytes = 10;
        $document->sha256 = str_repeat('a', 64);
        $document->status = KycDocument::PENDING;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('same store and authority');

        $document->save();
    }

    /** And it cannot be filed under another tenant's store while naming this record. */
    public function test_a_document_cannot_disagree_with_its_record_about_the_store(): void
    {
        [, $storeA] = $this->kycMerchantWithStore();
        [, $storeB] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($storeA, KycAuthority::STORE_SYRIA);

        $document = new KycDocument();
        $document->kyc_verification_id = $record->id;
        $document->store_id = $storeB->id;                          // the lie
        $document->authority = KycAuthority::STORE_SYRIA;
        $document->document_kind = 'passport';
        $document->disk = 'kyc';
        $document->storage_name = $storeB->id . '/store_syria/' . bin2hex(random_bytes(32));
        $document->original_name = 'p.png';
        $document->mime = 'image/png';
        $document->size_bytes = 10;
        $document->sha256 = str_repeat('a', 64);
        $document->status = KycDocument::PENDING;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('same store and authority');

        $document->save();
    }

    public function test_an_unknown_authority_is_refused_rather_than_normalised(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KycAuthority::normalise('Store_Syria ');
    }

    /**
     * THE DOCUMENT LISTS ARE PER AUTHORITY, so the three can ask for different papers. Asserted
     * because a single shared list would be the same conflation arriving through configuration.
     */
    public function test_the_configured_document_kinds_are_keyed_by_authority(): void
    {
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::PAYMENT_PROVIDER, ['bank_letter']);

        $this->assertSame(
            ['commercial_register'],
            KycAuthority::documentKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA)
        );
        $this->assertSame(
            ['bank_letter'],
            KycAuthority::documentKinds(KycVerification::COMPANY, KycAuthority::PAYMENT_PROVIDER)
        );
        $this->assertSame(
            [],
            KycAuthority::documentKinds(KycVerification::COMPANY, KycAuthority::GOVERNMENT_LICENCE),
            'an authority the owner has configured nothing for collects nothing'
        );
    }

    /** A record of an unknown authority cannot be written at all. */
    public function test_a_record_of_an_unknown_authority_is_refused_at_the_model(): void
    {
        [, $store] = $this->kycMerchantWithStore();

        $this->expectException(InvalidArgumentException::class);

        $this->kycRecord($store, 'some_other_registry');
    }

    /** `recordFor` is the only creator and it refuses an unknown authority too. */
    public function test_record_for_refuses_an_unknown_authority(): void
    {
        [, $store] = $this->kycMerchantWithStore();

        $this->expectException(InvalidArgumentException::class);

        KycReview::recordFor($store->id, 'some_other_registry', KycVerification::COMPANY);
    }

    public function test_record_for_refuses_an_unknown_subject_type(): void
    {
        [, $store] = $this->kycMerchantWithStore();

        $this->expectException(KycRefusal::class);

        KycReview::recordFor($store->id, KycAuthority::STORE_SYRIA, 'partnership');
    }

    /** Called twice it returns the same row rather than a second answer. */
    public function test_record_for_is_idempotent(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();

        $first = KycReview::recordFor($store->id, KycAuthority::STORE_SYRIA, KycVerification::COMPANY, $merchant);
        $second = KycReview::recordFor($store->id, KycAuthority::STORE_SYRIA, KycVerification::INDIVIDUAL, $merchant);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            DB::table('kyc_verifications')->where('store_id', $store->id)->count()
        );
        $this->assertSame(
            KycVerification::COMPANY,
            DB::table('kyc_verifications')->where('id', $first->id)->value('subject_type'),
            'the second call must not re-type an existing record'
        );
    }
}
