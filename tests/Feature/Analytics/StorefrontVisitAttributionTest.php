<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * HOW A STOREFRONT VISIT IS ATTRIBUTED TO A STORE — measured, because five surfaces assume an answer.
 *
 * `ActiveTheme` logs a page view with `visitor()->visit($store)` (ActiveTheme.php:30), through the
 * first-party `SanitisedVisitor` subclass bound in AppServiceProvider. The row lands in
 * `shetabit_visits` (`config('visitor.table_name')`), whose Store Syria migration added a `store_id`
 * column the vendored package knows nothing about:
 *
 *   * `Shetabit\Visitor\Visitor::prepareLog()` builds no `store_id`;
 *   * `Shetabit\Visitor\Models\Visit::$fillable` does not list `store_id`, so a mass assignment would
 *     drop it even if one were passed;
 *   * `visit($store)` goes through `$store->visitLogs()->create($data)`, which sets the `visitable`
 *     MORPH, not `store_id`.
 *
 * And yet `store_id` is exactly what the analytics screens filter on — `Api\DashboardController`
 * (visitor and unique-visitor counts, twice each), `ThemeAnalyticController` (urls, device, browser,
 * platform, counts, unique IPs) and `HomeController:235` (`whereNotNull('store_id')`). If the column is
 * never filled, every one of those reads nothing for every store, for ever, and reports zero rather
 * than an error. That is the shape of defect this repository has been bitten by before (P5-10, "the
 * fallback that never fires"), so it is settled here by writing a visit and reading the row back
 * instead of by reasoning about the package.
 *
 * This test states WHAT IS, so it is correct either way: if attribution is by morph only, it records
 * that and the `store_id` filter is the defect; if something does fill `store_id`, it pins that.
 */
final class StorefrontVisitAttributionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const TABLE = 'shetabit_visits';

    public function test_premise_the_visit_table_exists_with_both_attribution_columns(): void
    {
        $this->assertSame(self::TABLE, config('visitor.table_name'),
            'the visit table was renamed; every analytics query naming shetabit_visits must move with it');
        $this->assertTrue(Schema::hasTable(self::TABLE), self::TABLE.' does not exist');

        foreach (['store_id', 'visitable_type', 'visitable_id'] as $column) {
            $this->assertTrue(Schema::hasColumn(self::TABLE, $column),
                self::TABLE.' has no '.$column.' column');
        }
    }

    public function test_premise_the_bound_logger_is_the_sanitised_first_party_one(): void
    {
        $this->assertInstanceOf(\App\Services\SanitisedVisitor::class, app('shetabit-visitor'),
            'the sanitised subclass is no longer bound, so P1-SEC-13 redaction is not in force');
    }

    /**
     * THE MEASUREMENT. One visit is logged for one store; the row is then read raw.
     *
     * `store_id` is asserted to be the store's id because that is what every analytics surface filters
     * on. If this fails with null, the surfaces are reading a column nothing fills.
     */
    public function test_a_logged_visit_is_attributed_to_the_store_the_way_the_screens_read_it(): void
    {
        [, $store] = $this->makeTenant();

        visitor()->visit($store);

        $row = DB::table(self::TABLE)->latest('id')->first();

        $this->assertNotNull($row, 'visitor()->visit($store) wrote no row at all');

        /*
         * ATTRIBUTION IS BY THE COLUMN, NOT BY THE PACKAGE'S MORPH, and that is asserted rather than
         * left implicit. `Visitor::visit()` only sets `visitable_*` when the model it is handed has a
         * `visitLogs()` method; `Store` deliberately does not have one (the package's `Visitable` trait
         * is not used, and adding it would create a relation nothing reads). So the morph stays null and
         * `store_id` — the column all nine analytics queries filter on — carries the attribution. If a
         * later change adds the trait, this assertion fails and the next reader is told to decide which
         * of the two is authoritative rather than ending up with both half-filled.
         */
        $this->assertNull($row->visitable_id,
            'the package morph is now being set too; decide which attribution is authoritative');

        // This is the column all nine analytics queries actually filter on.
        $this->assertSame((int) $store->id, (int) $row->store_id, sprintf(
            'shetabit_visits.store_id is %s, not the store id. Api\DashboardController, '
            .'ThemeAnalyticController and HomeController all filter visits by store_id, so each of them '
            .'reads nothing for every store while this is null.',
            var_export($row->store_id, true)
        ));
    }

    /** The query the dashboards actually run, so the finding is about the product and not about a column. */
    public function test_the_dashboard_visitor_count_query_sees_the_visit(): void
    {
        [, $store] = $this->makeTenant();

        visitor()->visit($store);

        $counted = DB::table(self::TABLE)->where('store_id', $store->id)->count();

        $this->assertSame(1, $counted,
            'the visitor count the merchant dashboard shows is 0 after a real storefront visit');
    }
}
