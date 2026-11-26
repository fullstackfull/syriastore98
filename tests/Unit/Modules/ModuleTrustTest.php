<?php

namespace Tests\Unit\Modules;

use App\Models\ModuleReviewRecord;
use App\Support\Modules\ModuleTrust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MARKET-004 / EX-010 — the predicate that decides whether an uploaded package's code may run.
 *
 * EVERY DIRECTION IS ASSERTED, because the expensive failure here is the one that looks like a
 * pass. A test that only drives the REFUSAL would stay green if `mayExecute()` were mutated to
 * `return false`, and `return false` unloads all 70 vendored modules — `LandingPage` alone owns 275
 * routes. So "a package with no record is trusted" is asserted as hard as "an unreviewed upload is
 * refused", and `executable()` is driven over a mixed list rather than one name at a time.
 */
class ModuleTrustTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_the_review_register_table_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable(ModuleTrust::TABLE),
            'premise: every other assertion here reads this table, and ModuleTrust trusts everything when it is absent.'
        );
    }

    #[Test]
    public function test_a_package_with_no_record_may_execute(): void
    {
        $this->assertTrue(
            ModuleTrust::mayExecute('ProductReview'),
            'A module that shipped with the product has no review record. Refusing it would unload every vendored module.'
        );
    }

    #[Test]
    public function test_an_uploaded_package_awaiting_review_may_not_execute(): void
    {
        $this->record('UploadedMod', ModuleReviewRecord::STATE_PENDING);

        $this->assertFalse(ModuleTrust::mayExecute('UploadedMod'));
    }

    #[Test]
    public function test_an_uploaded_package_that_was_rejected_may_not_execute(): void
    {
        $this->record('RejectedMod', ModuleReviewRecord::STATE_REJECTED);

        $this->assertFalse(ModuleTrust::mayExecute('RejectedMod'));
    }

    #[Test]
    public function test_an_uploaded_package_that_was_approved_may_execute(): void
    {
        $this->record('ApprovedMod', ModuleReviewRecord::STATE_APPROVED);

        $this->assertTrue(ModuleTrust::mayExecute('ApprovedMod'));
    }

    /**
     * `provenance` is the discriminator, not the state. A row that says the package SHIPPED is
     * trusted whatever its review state — nothing writes such a row today, and the column exists so
     * that a future first-party record cannot accidentally refuse product code.
     */
    #[Test]
    public function test_a_shipped_record_may_execute_whatever_its_review_state(): void
    {
        $this->record('ShippedMod', ModuleReviewRecord::STATE_PENDING, ModuleReviewRecord::PROVENANCE_SHIPPED);

        $this->assertTrue(ModuleTrust::mayExecute('ShippedMod'));
    }

    /**
     * THE TWO SUBJECT TYPES ARE SEPARATE KEYS. An approved THEME called `x` must not approve a
     * MODULE called `x`: a theme review looks at Blade, a module review looks at a service provider.
     */
    #[Test]
    public function test_the_subject_type_is_part_of_the_key(): void
    {
        $this->record('shared-name', ModuleReviewRecord::STATE_APPROVED, ModuleReviewRecord::PROVENANCE_UPLOADED, ModuleReviewRecord::TYPE_THEME);

        $this->assertTrue(ModuleTrust::mayExecute('shared-name', ModuleReviewRecord::TYPE_THEME));
        $this->assertTrue(
            ModuleTrust::mayExecute('shared-name', ModuleReviewRecord::TYPE_MODULE),
            'No MODULE record exists for this name, so the module side has nothing recorded and is trusted as shipped code.'
        );

        $this->record('shared-name', ModuleReviewRecord::STATE_PENDING, ModuleReviewRecord::PROVENANCE_UPLOADED, ModuleReviewRecord::TYPE_MODULE);

        $this->assertTrue(ModuleTrust::mayExecute('shared-name', ModuleReviewRecord::TYPE_THEME));
        $this->assertFalse(
            ModuleTrust::mayExecute('shared-name', ModuleReviewRecord::TYPE_MODULE),
            'The theme approval must not carry over to the module of the same name.'
        );
    }

    #[Test]
    public function test_executable_keeps_the_trusted_and_drops_only_the_unreviewed(): void
    {
        $this->record('PendingMod', ModuleReviewRecord::STATE_PENDING);
        $this->record('OkMod', ModuleReviewRecord::STATE_APPROVED);

        $this->assertSame(
            ['ProductReview', 'OkMod', 'LandingPage'],
            ModuleTrust::executable(['ProductReview', 'PendingMod', 'OkMod', 'LandingPage']),
            'Order is preserved and exactly one name is dropped.'
        );
    }

    #[Test]
    public function test_an_empty_subject_is_not_refused(): void
    {
        $this->assertTrue(ModuleTrust::mayExecute(null));
        $this->assertTrue(ModuleTrust::mayExecute(''));
        $this->assertTrue(ModuleTrust::mayExecute('   '));
    }

    /**
     * THE FAILURE DIRECTION, WHICH IS THE ONE A MUTATION WOULD GET WRONG.
     *
     * No table means nothing has ever been recorded against this database, which is a fresh checkout
     * before `migrate` — the state the application is in today. Refusing there would brick a new
     * install, so this direction fails OPEN and is asserted so a later "fail closed everywhere"
     * tidy-up cannot pass silently.
     */
    #[Test]
    public function test_everything_is_trusted_when_the_register_has_not_been_migrated(): void
    {
        Schema::drop(ModuleTrust::TABLE);

        $this->assertFalse(Schema::hasTable(ModuleTrust::TABLE), 'premise');
        $this->assertTrue(ModuleTrust::mayExecute('AnythingAtAll'));
        $this->assertSame(['A', 'B'], ModuleTrust::executable(['A', 'B']));
    }

    private function record(
        string $subject,
        string $state,
        string $provenance = ModuleReviewRecord::PROVENANCE_UPLOADED,
        string $type = ModuleReviewRecord::TYPE_MODULE
    ): void {
        DB::table(ModuleTrust::TABLE)->insert([
            'subject_type' => $type,
            'subject' => $subject,
            'provenance' => $provenance,
            'review_state' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
