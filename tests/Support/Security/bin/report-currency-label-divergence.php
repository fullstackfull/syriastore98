#!/usr/bin/env php
<?php

/**
 * FIN-CUR — WHICH STORES' WALLETS DISAGREE WITH THEIR OWN CURRENCY SETTING.
 * =================================================================================================
 *
 * READ-ONLY. It writes nothing and changes nothing. Run it to find the stores whose currency was
 * changed through a writer that did not convert — the admin API before FIN-CUR — because those
 * balances and prices are still denominated in the old currency while every label says the new one.
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST. It is a question about PRODUCTION DATA, and the answer on a
 * database built from this repository's migrations is always "none". A test asserting that would
 * assert nothing. It also cannot be answered on SQLite with any confidence: the divergence this
 * looks for was created by a non-strict MySQL write.
 *
 * WHAT IT CANNOT TELL YOU, and this matters before anyone acts on it: a wallet whose `currency_code`
 * MATCHES the setting is not proof the amounts were converted, only that nothing recorded a
 * mismatch. The reverse is reliable — a mismatch means the two writers disagreed about this store.
 *
 * REPAIRING ANY OF IT IS NOT AN ENGINEERING DECISION. Rewriting a balance or a price list is a
 * modification of historical financial data; this script exists so the owner can see the size of
 * the question, not so anything can be fixed automatically.
 *
 *     php tests/Support/Security/bin/report-currency-label-divergence.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

foreach (['stores', 'settings', 'store_wallets'] as $table) {
    if (! Schema::hasTable($table)) {
        fwrite(STDERR, "PRECONDITION: `{$table}` is missing from this database. Nothing measured.\n");
        exit(2);
    }
}

$labels = DB::table('settings')
    ->where('name', 'defult_currancy')
    ->pluck('value', 'store_id');

$rows = DB::table('store_wallets')
    ->join('stores', 'stores.id', '=', 'store_wallets.store_id')
    ->select([
        'store_wallets.store_id',
        'stores.name as store',
        'store_wallets.currency_code',
        'store_wallets.available_balance',
        'store_wallets.pending_balance',
        'store_wallets.total_income',
    ])
    ->orderBy('store_wallets.store_id')
    ->get();

$diverged = [];
$agreed = 0;
$unlabelled = 0;

foreach ($rows as $row) {
    $label = strtoupper(trim((string) ($labels[$row->store_id] ?? '')));
    $wallet = strtoupper(trim((string) $row->currency_code));

    if ($label === '') {
        $unlabelled++;

        continue;
    }

    if ($label === $wallet) {
        $agreed++;

        continue;
    }

    $diverged[] = $row->store_id . "\t" . $wallet . ' -> ' . $label . "\t"
        . 'available=' . $row->available_balance . ' pending=' . $row->pending_balance
        . ' income=' . $row->total_income . "\t" . $row->store;
}

echo "FIN-CUR currency-label divergence, " . date('c') . "\n";
echo str_repeat('=', 78) . "\n";
echo 'wallets read:      ' . $rows->count() . "\n";
echo 'agree:             ' . $agreed . "\n";
echo 'no currency set:   ' . $unlabelled . "   (the wallet's own code is all there is)\n";
echo 'DIVERGED:          ' . count($diverged) . "\n\n";

if ($diverged === []) {
    echo "No wallet disagrees with its store's currency setting.\n";
    echo "That is not proof any store's amounts were converted -- only that no store recorded a\n";
    echo "mismatch. See the docblock.\n";
    exit(0);
}

echo "store\twallet -> label\tbalances\tname\n";
echo implode("\n", $diverged) . "\n\n";
echo "Each row is a store whose wallet records one currency while its setting names another.\n";
echo "The balances are printed so the size of the question is visible. Do not convert them from\n";
echo "this report: which rate applies to a past balance is a decision about money.\n";

exit(1);
