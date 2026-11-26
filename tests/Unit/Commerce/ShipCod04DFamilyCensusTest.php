<?php

namespace Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * `SHIP-001…004` + `COD-001…004` — SECTION 04-D RECONCILED AS A FAMILY, BULLET BY BULLET.
 * =================================================================================================
 *
 * **THE UNIT OF RECONCILIATION IS THE FAMILY, AND THAT IS THE REGISTER'S OWN RULING.**
 * `DECISION-ID-REGISTER.md` records both families as **"FAMILY-LEVEL ONLY"**, cites all eight ids
 * against ONE section (**04-D**) of **TEN** bullets, and says in as many words:
 *
 *   > *"The plan cites these ids against a section whose bullet count (10) does not equal the id
 *   > count (4), so no per-id pairing is derivable. The family's obligations are recovered in full;
 *   > which id carries which is not."*
 *
 * So nothing here is labelled `SHIP-002` or `COD-003`. Assigning one would be inventing a decision
 * text out of a family sentence, which `CLAUDE.md` §4.1 forbids. Each case below names its BULLET.
 *
 * **AND THE INTERLEAVING IS ITSELF AN OBLIGATION.** The register cites SHIP and COD against the
 * *same* section, interleaved — so the plan treats delivery and payment-on-delivery as ONE flow.
 * Bullets 6 and 7 are where that bites, and they are the two that read as satisfied per-half and
 * fail as a whole.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TEN BULLETS, MEASURED. 0 of 8 ids appear in the matrix, the gap register or the
 * source-verification document, so nothing had been reconciled before this.
 * -------------------------------------------------------------------------------------------------
 *  1. تكامل شركات توصيل متعددة — multiple delivery-company integrations.
 *     **ONE carrier**, `DeliverySyria`, plus the in-house `delivery_boys`. Not "multiple".
 *  2. اقتراح شركة التوصيل آلياً مع تعديل مصرح به — suggest the carrier automatically, with an
 *     authorised override. **The override exists and the suggestion does not**:
 *     `OrderController::order_assign()` validates `delivery_boy` as `required`, so a human always
 *     names the driver. There is nothing to override.
 *  3. شحنات جزئية ومستودعات متعددة — partial shipments, multiple warehouses. **NO mechanism**:
 *     no shipment table, no warehouse table, asserted over all 213 tables below.
 *  4. بيانات تتبع وتكلفة وناقل لكل شحنة — tracking, cost and carrier PER SHIPMENT. **NO mechanism
 *     for the platform's own orders**: `orders` has no carrier, tracking or shipment column, and
 *     the one `parcel_tracking` column in the schema belongs to `zid_orders`, a channel table.
 *  5. الحفاظ على فاتورة الطلب الأصلية الموحدة — preserve the order's original unified invoice.
 *     **VACUOUSLY HELD.** One order has one invoice because it cannot be split.
 *  6. منع تكرار خصم المخزون أو التحصيل بسبب تقسيم الشحنة — prevent duplicate stock deduction or
 *     duplicate COLLECTION caused by splitting a shipment. **TWO HALVES, DIFFERENT ANSWERS.**
 *     Duplicate collection is genuinely guarded (`CodStateMachineTest`). The *shipment-split*
 *     cause is **UNREACHABLE, NOT SATISFIED** — there is no shipment entity to split, so a
 *     reconciliation that marks this bullet green is reading an absence as a control.
 *  7. الفصل بين التسليم والتحصيل والتحويل للتاجر — separate delivery, collection, remittance.
 *     **TWO-WAY, NOT THREE-WAY.** Driven in `CodCollectionAndRemittanceSeparationTest`.
 *  8. استيراد كشوف COD والمطابقة واكتشاف الفروقات — import COD statements, reconcile, detect
 *     discrepancies. **DESIGNED AND DELIBERATELY DISABLED** under owner decision **D-15**:
 *     `cod_collection_events` must stay absent (`CodCollectionEventsStillAbsentTest`). Not a gap
 *     to close — an owner decision to respect.
 *  9. تسويات جزئية ومبالغ معلقة وسجلات قابلة للتدقيق — partial settlements, pending amounts,
 *     auditable records. **Auditable records YES** (driven); **pending amounts NO** (driven);
 *     **partial settlements** share bullet 8's D-15 gate.
 * 10. تقارير أداء لشركات التوصيل من بيانات فعلية — carrier performance reports from actual data.
 *     **NO mechanism**: `ReportController` names no driver or carrier dimension.
 *
 * -------------------------------------------------------------------------------------------------
 * A SOURCE AND SCHEMA CENSUS, NOT A DRIVEN MEASUREMENT, and it says so.
 * -------------------------------------------------------------------------------------------------
 * Absences are asserted by enumerating what the schema HAS and checking the concepts are not in
 * it — the safe-set direction `CLAUDE.md` requires, so a spelling nobody thought of is caught.
 * Every absence is also paired with a presence from the same census input, so none of them can be
 * a reading of an empty string. The driven figures live in the Feature files named above; nothing
 * here claims one.
 *
 * @see \Tests\Feature\Commerce\CodCollectionAndRemittanceSeparationTest  bullets 7 and 9, driven
 * @see \Tests\Feature\Commerce\CodStateMachineTest                       bullet 6's collection half
 * @see \Tests\Unit\Database\CodCollectionEventsStillAbsentTest            bullets 8-9, D-15
 * @see \Tests\Feature\Commerce\DeliverySyriaReturnRestockTest             bullet 1's one carrier
 */
final class ShipCod04DFamilyCensusTest extends TestCase
{
    use StripsSourceComments;

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every table name the repository's migrations create — host and all vendored modules.
     *
     * @return list<string>
     */
    private function tableNames(): array
    {
        $files = glob($this->root() . '/database/migrations/*.php') ?: [];

        foreach (glob($this->root() . '/packages/workdo/*/src/database/migrations/*.php') ?: [] as $packageFile) {
            $files[] = $packageFile;
        }

        $this->assertGreaterThan(100, count($files),
            'premise: the migration scan found the migrations. A scan that found none would make '
            . 'every absence below vacuous.');

        $names = [];

        foreach ($files as $file) {
            preg_match_all("/Schema::create\('([a-z0-9_]+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /*
    |--------------------------------------------------------------------------
    | BULLET 3 and 4 — partial shipments, multiple warehouses, per-shipment data
    |--------------------------------------------------------------------------
    */

    /**
     * **NO SHIPMENT AND NO WAREHOUSE EXIST AS THINGS**, so bullets 3 and 4 have no mechanism and
     * bullets 5 and 6 inherit their answers from that.
     *
     * The census enumerates the tables the schema HAS and asserts that none of them is a shipment
     * or a warehouse, rather than searching for a list of hoped-for names — so a table called
     * `consignments` or `parcels` would be caught by the same predicate.
     */
    public function test_bullets_3_and_4_no_shipment_or_warehouse_entity_exists_in_the_whole_schema(): void
    {
        $tables = $this->tableNames();

        $suspects = array_values(array_filter($tables, fn (string $t) => (bool) preg_match(
            '/shipment|warehouse|consign|manifest|parcel|stock_location|inventory_location|fulfil/',
            $t
        )));

        $this->assertSame([], $suspects, sprintf(
            'bullets 3 and 4 ask for partial shipments, multiple warehouses, and tracking/cost/'
            . 'carrier PER SHIPMENT. A table matching one of those concepts has appeared, so the '
            . 'mechanism may now exist and this whole reconciliation must be re-read before the '
            . 'family is disposed. Candidates: %s', implode(', ', $suspects)
        ));

        // PREMISE, the other direction: the census input really is this repository's schema, so
        // the absence above is a reading of 213 tables and not of an empty list.
        $this->assertContains('orders', $tables, 'premise: the census read the real schema.');
        $this->assertContains('shipping_methods', $tables,
            'premise: and it DOES contain the shipping vocabulary that exists -- a method and a '
            . 'zone. The gap is the shipment, not the word "shipping".');

        $this->assertContains('shippings', $tables,
            'premise, and a trap worth naming: a table called `shippings` EXISTS, and it is a '
            . 'name/description/slug lookup of shipping providers -- not a shipment record. A '
            . 'census keyed on the word "shipping" would read it as shipment support and be '
            . 'wrong, which is why the predicate above keys on the CONCEPT.');
    }

    /**
     * AND THE ORDER CARRIES NO PER-SHIPMENT DATA EITHER — bullet 4 from the other side.
     *
     * `orders` has `delivery_id` and `deliveryboy_id` (who delivers) but no carrier name, no
     * tracking number and no shipment reference. The only `parcel_tracking` column in the schema
     * is on `zid_orders`, a sales-channel table, so it serves that channel and not the platform's
     * own fulfilment.
     */
    public function test_bullet_4_the_orders_table_has_no_carrier_tracking_or_shipment_column(): void
    {
        $migrations = array_merge(
            glob($this->root() . '/database/migrations/*orders_table.php') ?: [],
            glob($this->root() . '/database/migrations/*to_orders_table.php') ?: []
        );

        $this->assertNotSame([], $migrations, 'premise: the orders migrations were found.');

        $columns = [];

        foreach ($migrations as $file) {
            $isPlatformOrders = ! str_contains(basename($file), 'zid')
                && ! str_contains(basename($file), 'plan_orders');

            if (! $isPlatformOrders) {
                continue;
            }

            preg_match_all("/table->[a-zA-Z]+\('([a-z0-9_]+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $column) {
                $columns[$column] = true;
            }
        }

        $this->assertArrayHasKey('delivered_status', $columns,
            'premise: the census read the platform orders table, which carries ONE delivery state '
            . 'for the WHOLE order.');

        foreach (['tracking_number', 'parcel_tracking', 'carrier', 'carrier_name', 'shipment_id', 'awb'] as $absent) {
            $this->assertArrayNotHasKey($absent, $columns, sprintf(
                'bullet 4 asks for tracking, cost and carrier per shipment. `orders.%s` has '
                . 'appeared, so per-shipment data may now exist. Columns found: %s',
                $absent, implode(', ', array_keys($columns))
            ));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BULLET 6 — and this is the one a naive reconciliation marks green
    |--------------------------------------------------------------------------
    */

    /**
     * **BULLET 6's SHIPMENT-SPLIT CAUSE IS UNREACHABLE, WHICH IS NOT THE SAME AS SATISFIED.**
     *
     * *"Prevent duplicate stock deduction or duplicate collection caused by splitting a shipment."*
     * There is no shipment entity (above) and `orders.delivered_status` is a single value for the
     * whole order, so an order cannot be partially shipped and the stated CAUSE cannot occur.
     *
     * A reconciliation that recorded "no duplicate deduction observed" would be reading an absence
     * as a control. The honest disposition is: the prohibition holds today **because the feature it
     * constrains does not exist**, and it becomes a live requirement the moment bullet 3 is built.
     * This test exists to make that conditional explicit — if a shipment table ever arrives, the
     * test above reddens and a reader lands here.
     */
    public function test_bullet_6_the_split_shipment_cause_cannot_occur_because_the_order_has_one_delivery_state(): void
    {
        $machine = $this->sourceWithoutComments('app/Support/Orders/FulfilmentStateMachine.php');

        foreach (['STATE_DELIVERED', 'STATE_SHIPPED', 'STATE_PICKED_UP'] as $state) {
            $this->assertStringContainsString($state, $machine, sprintf(
                'premise: the fulfilment state machine declares %s, so it really is what governs '
                . 'delivery progress.', $state
            ));
        }

        foreach (['shipment', 'per_line', 'partial_shipment', 'line_status'] as $absent) {
            $this->assertStringNotContainsString($absent, $machine, sprintf(
                'the fulfilment state machine now names `%s`. If delivery progress has become '
                . 'per-line or per-shipment, bullet 6\'s prohibition stops being vacuous and '
                . 'becomes a live requirement with a MONEY consequence (duplicate collection). '
                . 'Read this docblock before relaxing this.', $absent
            ));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BULLETS 1, 2 and 10
    |--------------------------------------------------------------------------
    */

    /**
     * BULLET 1: **one** carrier integration, not "multiple delivery companies".
     *
     * Counted from the host's own controllers rather than from a module list, because the one
     * integration that exists is host-side and a module census would miss it.
     */
    public function test_bullet_1_exactly_one_external_carrier_integration_exists(): void
    {
        $controllers = glob($this->root() . '/app/Http/Controllers/*.php') ?: [];

        $this->assertGreaterThan(50, count($controllers), 'premise: the controller scan found them.');

        $carriers = [];

        foreach (array_merge($controllers, glob($this->root() . '/app/Http/Controllers/Api/*.php') ?: []) as $file) {
            if (preg_match('/^(DeliverySyria|Aramex|DHL|Fedex|Bosta|Shipox|Mylerz)/', basename($file), $m)) {
                $carriers[$m[1]] = true;
            }
        }

        $this->assertSame(['DeliverySyria'], array_keys($carriers), sprintf(
            'bullet 1 asks for integration with MULTIPLE delivery companies. Exactly one exists. '
            . 'A second arriving is progress and must be recorded against the family rather than '
            . 'absorbed. Found: %s', implode(', ', array_keys($carriers))
        ));
    }

    /**
     * BULLET 2: the **authorised override exists and the automatic suggestion does not**, which is
     * the half of the bullet that is easy to miss.
     *
     * `order_assign` validates `delivery_boy` as `required|integer|exists:delivery_boys,id` and
     * then calls `$this->authorize('assign', $order)`. So a human always names the driver, under a
     * policy — the override is built, and there is nothing for it to override.
     */
    public function test_bullet_2_driver_assignment_is_manual_and_authorised_with_no_automatic_suggestion(): void
    {
        $source = $this->sourceWithoutComments('app/Http/Controllers/OrderController.php');

        $start = strpos($source, 'function order_assign');
        $this->assertNotFalse($start, 'premise: the assignment handler exists.');

        $handler = substr($source, $start, 2500);

        $this->assertStringContainsString("'delivery_boy' => 'required", $handler,
            'MEASURED: the driver is a REQUIRED request parameter, so assignment is manual. '
            . 'Bullet 2 asks for an automatic suggestion with an authorised override.');

        $this->assertStringContainsString("authorize('assign'", $handler,
            'and the override half IS built: the assignment is policy-gated. So bullet 2 is '
            . 'half-satisfied, not unsatisfied, and saying only "no auto-assignment" would '
            . 'understate what exists.');

        foreach (['suggest', 'recommend', 'auto_assign', 'autoAssign'] as $absent) {
            $this->assertStringNotContainsString($absent, $handler, sprintf(
                'a suggestion mechanism (`%s`) has appeared in the assignment handler; bullet 2 '
                . 'may now be satisfiable and the family disposition must be re-read.', $absent
            ));
        }
    }

    /**
     * BULLET 10: no carrier or driver performance reporting from actual data.
     *
     * Asserted against `ReportController`, which is where every other report in the product lives,
     * and paired with a presence so the absence is a reading of the file. **Most of that controller
     * cannot run under the suite** (37 `MONTH(` / 10 `YEAR(` in `selectRaw`, absent from SQLite),
     * which is exactly why this is a SOURCE census and not a driven one.
     */
    public function test_bullet_10_no_carrier_or_driver_performance_report_exists(): void
    {
        $source = $this->sourceWithoutComments('app/Http/Controllers/ReportController.php');

        $this->assertStringContainsString('function', $source, 'premise: the file was read.');
        $this->assertGreaterThan(50000, strlen($source),
            'premise: and it is the real 3,660-line report controller, not a stub.');

        foreach (['delivery_boy', 'deliveryboy_id', 'carrier', 'delivery_company'] as $absent) {
            $this->assertStringNotContainsString($absent, $source, sprintf(
                'bullet 10 asks for delivery-company performance reports from ACTUAL data. '
                . '`%s` has appeared in ReportController, so a carrier dimension may now exist. '
                . 'Columns that would feed it already exist in `delivery_earnings` '
                . '(delivery_boy_id, shipping_cost, commission_amount, status, paid_at), so this '
                . 'bullet is buildable rather than blocked.', $absent
            ));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BULLET 8 — the one that must NOT be built
    |--------------------------------------------------------------------------
    */

    /**
     * BULLET 8 IS GATED BY AN OWNER DECISION AND IS NOT A GAP TO CLOSE.
     *
     * *"Import COD statements, reconcile, detect discrepancies."* The machinery is designed:
     * `CodCollectionService` guards on its own `TABLE` constant and
     * `PaymentVerificationService::codMayBeSettled()` returns false without an event. The table is
     * **deliberately absent under decision D-15**, and `GATE-F-RECONCILIATION-ITEMS.md` R2 (17 COD
     * orders recorded paid with no collection evidence) depends on that absence for its premise.
     *
     * This test asserts the SERVICES still exist and still guard, which is the half a reader needs
     * in order to understand that bullet 8 is **blocked, not missing**. The absence of the table
     * itself is `CodCollectionEventsStillAbsentTest`'s subject and is not duplicated here.
     */
    public function test_bullet_8_the_settlement_machinery_exists_and_is_gated_rather_than_absent(): void
    {
        $service = $this->sourceWithoutComments('app/Services/CodCollectionService.php');

        $this->assertStringContainsString('cod_collection_events', $service,
            'premise: the service names the table its own guard is keyed on, so bullet 8 is '
            . 'DESIGNED. Reporting it as "missing functionality" and building it would silently '
            . 'activate settlement behaviour against decision D-15.');

        $verification = $this->sourceWithoutComments('app/Services/PaymentVerificationService.php');

        $this->assertStringContainsString('codMayBeSettled', $verification,
            'and the predicate that consumes it exists too. Both halves are written; only the '
            . 'table is withheld, and withholding it is the decision.');
    }
}
