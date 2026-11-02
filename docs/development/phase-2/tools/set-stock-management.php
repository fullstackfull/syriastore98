<?php
/**
 * Turn the store's `stock_management` setting on or off, for harnesses that need the stock fields.
 *
 * WHY A SCRIPT AND NOT A FIXTURE CHANGE. `low_stock_threshold` lives inside
 * `@if ($stock_management == 'on')` in both product templates, so with the setting unset -- which
 * is how it ships -- that field is not on the page. Turning it on in `seed-p2dev.php` would change
 * the RENDERED FIELD SET of the editor, which `inventory-product-fields.cjs` diffs as its
 * preservation gate; a fixture that silently adds fields makes that gate's baseline drift for
 * reasons unrelated to the change under test. So the harnesses that need the field turn it on
 * themselves and turn it back off, the same way `set-preview-state.php` handles theme and
 * direction.
 *
 * usage: php set-stock-management.php <storeId> <on|off>
 *
 * Positional args and an owner resolved FROM THE STORE, matching `set-preview-state.php` beside
 * it: `settings.created_by` is NOT NULL and not mass-assignable, so hardcoding an owner id writes
 * the setting where the store will not read it.
 */
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $storeId, $value] = array_pad($argv, 3, null);

if (! $storeId || ! in_array($value, ['on', 'off'], true)) {
    fwrite(STDERR, "usage: set-stock-management.php <storeId> <on|off>\n");
    exit(2);
}

$ownerId = \App\Models\Store::where('id', $storeId)->value('created_by');

if (! $ownerId) {
    fwrite(STDERR, "store {$storeId} does not exist, so there is no owner to write the setting for\n");
    exit(2);
}

\App\Models\Setting::updateOrCreate(
    ['name' => 'stock_management', 'created_by' => $ownerId, 'store_id' => (int) $storeId],
    ['value' => $value]
);
\Illuminate\Support\Facades\Cache::flush();

echo "stock_management = {$value} for store {$storeId} (owner {$ownerId})\n";
