<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * P1-ORD-06 / P1-INV-16 -- THE AUCTION ORDER PATH IS DORMANT, AND THESE TESTS PIN THE PREMISE THAT MAKES THAT TRUE.
 * =================================================================================================================
 *
 * Both rows are defects in code that only runs on an existing `auction_product_orders` row the caller's own store owns:
 *   - ORD-06: `AuctionOrdersController::order_status_change` reads the HOST orders table with the AUCTION id and posts the result (billing PII
 *     included) to a merchant-configured webhook. A merchant can self-serve that webhook (WebhookController::store checks no permission and no
 *     module name), so the only precondition left is the auction order row.
 *   - INV-16: `AuctionProductOrder::product_return` restocks an unscoped Product, has no replay guard and a shadowed variable.
 * The route guard (ModuleOrderMutationGuard) authorises exactly one thing -- that the NAMED auction order is the caller's -- so a caller with
 * no auction order of their own cannot reach either handler past it.
 *
 * Auction orders are written by exactly ONE thing: the `auction:order` console command (Console/Auction.php:92), which the owner has decided to
 * keep UNSCHEDULED (docs/development/phase-1/15-DEFERRED-OWNER-DECISIONS.md D-03). No route creates one. So while D-03 holds there is no
 * auction order to name, and both defects are unreachable; the rows were therefore moved to BACKLOG with "must be closed BEFORE D-03 is
 * revisited" rather than being fixed against an unreachable surface.
 *
 * That is a claim about the whole tree, so it is asserted here. If this file reddens, someone has added a second writer or scheduled the
 * command, and ORD-06 / INV-16 are live again and must be closed first.
 *
 * What this does NOT establish (operator check, recorded in the ledger): that production has no pre-existing auction_product_orders rows,
 * e.g. from a manual run of the command before D-03. `SELECT count(*) FROM auction_product_orders` is the whole check.
 */
class AuctionOrderDormancyTest extends TestCase
{
    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];
        $roots = [app_path(), base_path('packages/workdo'), base_path('routes'), base_path('bootstrap')];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), '/vendor/') && ! str_contains($file->getPathname(), '/node_modules/')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    public function test_the_console_command_is_the_only_writer_of_auction_orders(): void
    {
        $writers = [];

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'AuctionProductOrder') && ! str_contains($source, 'auction_product_orders')) {
                continue;
            }

            // a construction, a mass create/insert, or a raw table insert
            if (preg_match('/new\s+\\\\?(?:[A-Za-z\\\\]*\\\\)?AuctionProductOrder\s*\(|AuctionProductOrder::(?:create|insert|firstOrCreate|updateOrCreate|forceCreate)\s*\(|table\(\s*[\'"]auction_product_orders[\'"]\s*\)\s*->\s*insert/', $source) === 1) {
                $writers[] = str_replace(base_path() . '/', '', $file);
            }
        }

        sort($writers);

        $this->assertSame(['packages/workdo/AuctionProduct/src/app/Console/Auction.php'], $writers,
            'Something other than the unscheduled auction:order command can now create an auction order. P1-ORD-06 and P1-INV-16 are reachable again.');
    }

    public function test_the_auction_command_is_registered_as_a_command_but_scheduled_nowhere(): void
    {
        $scheduled = [];

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);

            // Schedule::command('auction:order'), $schedule->command('auction:order'), ->call(...) of the class, or an exec of artisan
            if (preg_match('/(?:schedule|Schedule)[^;]{0,200}auction:order|auction:order[^;]{0,200}->(?:daily|hourly|everyMinute|everyFiveMinutes|cron|at|dailyAt|weekly|monthly|everyThirtyMinutes|everyTenMinutes)/s', $source) === 1) {
                $scheduled[] = str_replace(base_path() . '/', '', $file);
            }
        }

        $this->assertSame([], $scheduled, 'auction:order is scheduled (owner decision D-03 keeps it unscheduled).');

        $signature = (string) file_get_contents(base_path('packages/workdo/AuctionProduct/src/app/Console/Auction.php'));
        $this->assertStringContainsString("protected \$signature = 'auction:order';", $signature, 'Premise: the command still exists under this name, so the scan above is looking for the right string.');
    }

    public function test_no_route_creates_an_auction_order(): void
    {
        $routes = (string) file_get_contents(base_path('packages/workdo/AuctionProduct/src/routes/web.php'));

        foreach (['auction-order-assign', 'auction-order-status-change', 'auction-order-return'] as $mutation) {
            $this->assertStringContainsString($mutation, $routes, "Premise: {$mutation} is a module route (so the census sees the module's routes).");
        }

        $controller = (string) file_get_contents(base_path('packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionOrdersController.php'));
        $this->assertDoesNotMatchRegularExpression('/new\s+AuctionProductOrder|AuctionProductOrder::create/', $controller, 'The orders controller can create an auction order.');
    }
}
