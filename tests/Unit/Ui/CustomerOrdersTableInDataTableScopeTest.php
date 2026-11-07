<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE CUSTOMER'S ORDERS TABLE IS INSIDE THE DATATABLE SCOPE.  P2.5-WAVE-13
 * =================================================================================================
 *
 * `customer/view.blade.php` (customer.show) renders the per-customer orders table as
 * `<table class="table mb-0 dataTable" id="pc-dt-export">`. It carries the `dataTable` CLASS, so
 * `public/js/custom.js:12` really does initialise it -- it is a live DataTable, not a static table.
 * That makes `.ss-dt-card-body` the correct treatment here and `.ss-table` the wrong one: all 51
 * of P2.5-WAVE-1's rules are scoped `.ss-dt-card-body table.dataTable`, and this view's
 * `.card-body table-border-style` wrapper was outside that scope, so it received none of them --
 * no header band, no row rule, no pinned action column, no dark-mode `--ss-dt-card-bg`.
 *
 * ADDING THE CLASS IS SAFE AND IS NOT A RESTYLE OF THE WRAPPER. Measured: all three own-element
 * `.ss-dt-card-body` rules declare ONLY custom properties (`--ss-dt-card-bg`, `--ss-dt-tool-*`,
 * `--ss-dt-ident-*`, `--ss-dt-head-*`, `--ss-dt-rule`, `--ss-dt-empty-*`); the 47 rules that paint
 * anything are descendant rules consuming them. So the wrapper gains no padding and no background
 * of its own -- it only establishes the variable scope the table rules read.
 *
 * THE WRAPPER NOW MATCHES THE EMITTER. `components/datatable.blade.php:37` is
 * `<div class="card-body table-border-style ss-dt-card-body">`; this view now says the same thing,
 * which is why the treatment is identical rather than approximate.
 *
 * Verified on the running application in all four combinations (evidence/wave-13). Reaching it at
 * all needed a fixture grant: the dev role carried `Manage Customer` but not `Show Customer`, so
 * the screen had been answering with a REDIRECT TO THE DASHBOARD -- HTTP 200 with the wrong title,
 * which is the most dangerous reading this harness can produce because it looks like evidence.
 * `tools/grant-dev-screen-permissions.php` closes that gap.
 */
class CustomerOrdersTableInDataTableScopeTest extends TestCase
{
    private const VIEW = 'resources/views/customer/view.blade.php';
    private const EMITTER = 'resources/views/components/datatable.blade.php';
    private const SHEET = 'public/css/store-syria-ds.css';

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** PREMISE. The Wave-1 rules must still be scoped to this class, or the wrapper means nothing. */
    public function test_the_datatable_rules_are_still_scoped_to_the_card_body_class(): void
    {
        $this->assertStringContainsString(
            '.ss-dt-card-body',
            $this->read(self::SHEET),
            'the .ss-dt-card-body scope is gone from the stylesheet'
        );
    }

    /** PREMISE. The table is a real DataTable, which is why this treatment and not .ss-table. */
    public function test_the_customer_orders_table_is_a_datatable(): void
    {
        $this->assertMatchesRegularExpression(
            '/<table\b[^>]*\bclass\s*=\s*"[^"]*\bdataTable\b/',
            $this->read(self::VIEW),
            'the customer orders table no longer carries the dataTable class, so .ss-dt-card-body '
                . 'is no longer the right treatment for it'
        );
    }

    /** The wrapper is inside the scope. */
    public function test_the_customer_orders_table_wrapper_is_in_the_datatable_scope(): void
    {
        $this->assertMatchesRegularExpression(
            '/<div\b[^>]*\bclass\s*=\s*"[^"]*\bcard-body\b[^"]*\bss-dt-card-body\b/',
            $this->read(self::VIEW),
            'customer/view.blade.php renders its orders table outside .ss-dt-card-body, so none of '
                . "P2.5-WAVE-1's 51 DataTable rules reach it"
        );
    }

    /** And it says the same thing the shared emitter says, rather than something approximate. */
    public function test_the_wrapper_matches_the_shared_datatable_emitter(): void
    {
        foreach ([self::VIEW, self::EMITTER] as $file) {
            $this->assertStringContainsString(
                'card-body table-border-style ss-dt-card-body',
                $this->read($file),
                "{$file} no longer carries the emitter's exact wrapper class string"
            );
        }
    }
}
