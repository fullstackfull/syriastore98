<?php

/**
 * P1-PLT-02 -- COUNT the `orders` rows that carry no store (store_id NULL or 0), and say what they look like. READ ONLY.
 *
 * Why these rows exist: five plan-payment handlers (Mercado, Skrill, Paystack, Razorpay, PaymentWall) wrote a plan purchase with
 * `Order::create(['plan_name' => ..., 'plan_id' => ...])` -- the HOST order model, which keeps only the columns it lists -- so what landed
 * was a blank host order: payment_type the gateway's name, payment_status 'succeeded', store_id NULL, no customer, no lines. This script is
 * how the owner learns whether any such rows exist on the real database. It issues SELECT statements only and changes nothing.
 *
 * Precondition: run it where the application's own database configuration (.env) points at the database you want to read, e.g.
 *     php tests/Support/Security/bin/report-null-store-orders.php
 * It is also safe on the test database (it prints zeros).
 *
 * Output: the total, a breakdown by payment_type / payment_status, how many have no customer and no product_json (the plan-purchase
 * fingerprint), and the oldest/newest created_at. No row content is printed.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasTable('orders')) {
    fwrite(STDERR, "The orders table does not exist on this connection (" . config('database.default') . ").\n");
    exit(2);
}

$noStore = fn ($query) => $query->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', 0));

$total = $noStore(DB::table('orders'))->count();
printf("connection: %s\norders with no store (store_id NULL or 0): %d of %d\n", config('database.default'), $total, DB::table('orders')->count());

if ($total === 0) {
    exit(0);
}

echo "\nby payment_type / payment_status:\n";

foreach ($noStore(DB::table('orders'))->select('payment_type', 'payment_status', DB::raw('count(*) as n'))->groupBy('payment_type', 'payment_status')->orderByDesc('n')->get() as $row) {
    printf("  %-24s %-14s %d\n", (string) $row->payment_type, (string) $row->payment_status, $row->n);
}

$fingerprint = $noStore(DB::table('orders'))
    ->where(fn ($q) => $q->whereNull('customer_id')->orWhere('customer_id', 0))
    ->where(fn ($q) => $q->whereNull('product_json')->orWhere('product_json', '')->orWhere('product_json', '[]'))
    ->count();

printf("\nof which no customer and no lines (the blank-plan-purchase shape): %d\n", $fingerprint);
printf("created_at range: %s .. %s\n", $noStore(DB::table('orders'))->min('created_at'), $noStore(DB::table('orders'))->max('created_at'));
echo "\nNothing was modified. Deciding what to do with these rows is the owner's (preserve financial records; do not delete blindly).\n";
