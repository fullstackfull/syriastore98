<?php
/**
 * Reads the SERVER-SIDE preview state that `set-preview-state.php` writes, so a harness can put
 * back what it found instead of a hard-coded pair.
 *
 * WHY IT EXISTS. The Phase 02 browser harnesses all flip `cust_darklayout` and `SITE_RTL` to drive
 * their four configurations, and every one of them ended on a hard-coded restore -- most of them
 * `('light', 'rtl')`. Running any of them therefore left the dev fixture in whatever that author
 * happened to type, for whoever worked next: independent review of P2-03-10 found the store sitting
 * at `SITE_RTL=on` for exactly this reason. A restore that does not read is a reset.
 *
 * Prints the same two words `set-preview-state.php` takes, as JSON, so the pair round-trips.
 *
 * usage: php read-preview-state.php <storeId>     ->  {"theme":"light","dir":"ltr"}
 */
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $storeId] = array_pad($argv, 2, null);

if (! $storeId) {
    fwrite(STDERR, "usage: read-preview-state.php <storeId>\n");
    exit(2);
}

$value = static fn (string $name): ?string => \Illuminate\Support\Facades\DB::table('settings')
    ->where('name', $name)
    ->where('store_id', $storeId)
    ->value('value');

echo json_encode([
    'theme' => $value('cust_darklayout') === 'on' ? 'dark' : 'light',
    'dir' => $value('SITE_RTL') === 'on' ? 'rtl' : 'ltr',
]);
