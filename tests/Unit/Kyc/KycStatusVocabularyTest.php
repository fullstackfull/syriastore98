<?php

namespace Tests\Unit\Kyc;

use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Services\Kyc\KycAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\Kyc\MakesKycFixtures;
use Tests\TestCase;

/**
 * THE STATUS AND AUTHORITY VOCABULARIES ARE STRING COLUMNS, SO SOMETHING HAS TO BE THE ENUM.
 * =================================================================================================
 *
 * WHY NOT A MySQL `enum` COLUMN: extending one needs an `ALTER TABLE`, which on a live table is an
 * owner escalation under this project's standing rule. So the vocabulary is enforced at the model's
 * `saving` hook and pinned here.
 *
 * THE MIGRATIONS' COMMENTS NAME THIS FILE, which is the other half: a comment listing the accepted
 * values is documentation that can rot, and a test that reads the comment would be the
 * census-matches-its-own-prose failure this project has recorded eight times. So the comment names
 * the CONSTANT and this file asserts the constant is what the writers accept — in both directions.
 */
class KycStatusVocabularyTest extends TestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;

    public function test_the_verification_statuses_are_the_declared_set(): void
    {
        $this->assertSame(
            ['unsubmitted', 'submitted', 'in_review', 'approved', 'rejected', 'expired'],
            KycVerification::STATUSES
        );
    }

    public function test_the_document_statuses_are_the_declared_set(): void
    {
        $this->assertSame(
            ['pending', 'approved', 'rejected', 'superseded', 'expired'],
            KycDocument::STATUSES
        );
    }

    public function test_the_subject_types_are_the_two_kyc_001_names(): void
    {
        $this->assertSame(['individual', 'company'], KycVerification::SUBJECT_TYPES);
    }

    /** Every declared status can actually be written — the other direction. */
    public function test_every_declared_verification_status_is_writable(): void
    {
        [, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        foreach (KycVerification::STATUSES as $status) {
            $record->status = $status;
            $record->save();

            $this->assertSame(
                $status,
                \Illuminate\Support\Facades\DB::table('kyc_verifications')->where('id', $record->id)->value('status')
            );
        }
    }

    public function test_an_undeclared_verification_status_is_refused(): void
    {
        [, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $record->status = 'approvd';        // the typo a free varchar would accept

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('known status');

        $record->save();
    }

    /** Both subject types can be written, so KYC-001's two subjects are really both supported. */
    public function test_both_subject_types_are_writable(): void
    {
        [, $store] = $this->kycMerchantWithStore();

        $individual = $this->kycRecord($store, KycAuthority::STORE_SYRIA, KycVerification::INDIVIDUAL);
        $company = $this->kycRecord($store, KycAuthority::PAYMENT_PROVIDER, KycVerification::COMPANY);

        $this->assertSame('individual', $individual->fresh()->subject_type);
        $this->assertSame('company', $company->fresh()->subject_type);
    }

    public function test_an_undeclared_subject_type_is_refused(): void
    {
        [, $store] = $this->kycMerchantWithStore();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('known subject type');

        $this->kycRecord($store, KycAuthority::STORE_SYRIA, 'sole_trader');
    }

    public function test_a_record_with_no_store_is_refused(): void
    {
        $record = new KycVerification();
        $record->store_id = 0;
        $record->authority = KycAuthority::STORE_SYRIA;
        $record->subject_type = KycVerification::COMPANY;
        $record->status = KycVerification::UNSUBMITTED;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must belong to a store');

        $record->save();
    }

    /**
     * THE IDENTIFYING COLUMNS ARE NOT MASS-ASSIGNABLE. A fillable `store_id` or `authority` would
     * let one request move a REVIEWED decision between stores or authorities — the single thing
     * KYC-003 exists to make impossible — and a fillable `storage_name` would be a request-named
     * path, the most productive defect shape in this codebase.
     */
    public function test_the_identifying_columns_are_not_fillable(): void
    {
        $record = new KycVerification();

        foreach (['store_id', 'authority', 'subject_type', 'id'] as $column) {
            $this->assertFalse($record->isFillable($column), "KycVerification::{$column} must not be fillable");
        }

        $document = new KycDocument();

        foreach (['store_id', 'authority', 'kyc_verification_id', 'disk', 'storage_name', 'sha256', 'size_bytes', 'mime', 'id'] as $column) {
            $this->assertFalse($document->isFillable($column), "KycDocument::{$column} must not be fillable");
        }
    }

    /**
     * AND NEITHER MODEL IS `Cachable`. CLAUDE.md records three measured false readings where a
     * cached model served the pre-write row; for an authorization-bearing record the stale answer
     * is "still approved".
     */
    public function test_neither_model_is_cached(): void
    {
        foreach ([KycVerification::class, KycDocument::class] as $model) {
            $this->assertNotContains(
                'GeneaLabs\LaravelModelCaching\Traits\Cachable',
                class_uses_recursive($model),
                $model . ' must not be Cachable: a stale "approved" is an authorization answer'
            );
        }
    }
}
