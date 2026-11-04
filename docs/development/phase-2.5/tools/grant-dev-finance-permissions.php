<?php

/**
 * GRANT THE SIX FINANCE PERMISSIONS TO THE DEV FIXTURE MERCHANT. Dev fixture only.
 * =================================================================================================
 *
 * WHY THIS EXISTS. render-matrix.cjs signs in as merchant@example.test, and every /finance* row of
 * the first full run came back 403 Forbidden, so the finance screens -- which carry several of this
 * phase's repairs -- could not be photographed at all.
 *
 * THAT 403 IS CORRECT BEHAVIOUR, NOT A DEFECT, and it is worth knowing why before "fixing" it:
 * Gate::define('finance') in app/Providers/AuthServiceProvider.php is operator-first (a
 * `super admin` passes without consulting any row) and falls through to isAbleTo() for everyone
 * else -- while `Utility::addNewData()`, the only thing in this application that creates permission
 * rows, contains ZERO occurrences of "Finance". So on any database built from source none of the
 * six rows exists, and a missing Laratrust row is indistinguishable from a denial. That is a
 * documented lockout, recorded at AuthServiceProvider.php:47-82.
 *
 * So there are two honest ways to render these screens, and they show DIFFERENT things:
 *
 *   * as the OPERATOR -- the gate lets them in seed-independently, but finance/index.blade.php
 *     gates its own Add/Deduct/Withdraw controls on bare isAbleTo() with no operator limb, so the
 *     operator sees the screen with NO action buttons. That is a real recorded inconsistency, and
 *     photographing it is useful, but it is not what a merchant sees.
 *
 *   * as a PERMITTED MERCHANT -- which is who the repairs were written for, and what this script
 *     produces.
 *
 * It mirrors tests/Feature/Security/SecurityTestCase::grantPermission() exactly, including
 * Role::forceCreate (plain create() drops store_id) and the flushCache() at the end, which is NOT
 * optional: User is Cachable, so without it isAbleTo() keeps answering from the pre-grant row and
 * the screens stay 403 while this script reports success.
 *
 * usage:  DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *           php docs/development/phase-2.5/tools/grant-dev-finance-permissions.php
 *
 * It must run with the SAME DB and cache env as serve-dev.sh, or it grants rights in one sqlite
 * file and the server keeps serving another.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

$email = $argv[1] ?? 'merchant@example.test';

$NAMES = [
    'View Finance Dashboard',
    'Add Finance Balance',
    'Deduct Finance Balance',
    'Request Finance Withdrawal',
    'View Finance Withdrawal Requests',
    'Edit Finance Payment Status',
];

$user = User::where('email', $email)->first();

if (! $user) {
    fwrite(STDERR, "no such user: {$email}\n");
    exit(1);
}

$role = Role::where('name', 'dev-finance-role-' . $user->id)->first();

if (! $role) {
    $role = Role::forceCreate([
        'name' => 'dev-finance-role-' . $user->id,
        'display_name' => 'Dev Finance Role',
        'guard_name' => 'web',
        'created_by' => $user->creatorId(),
        'store_id' => $user->current_store,
    ]);
}

foreach ($NAMES as $name) {
    $role->givePermission(Permission::firstOrCreate(['name' => $name], ['display_name' => $name]));
}

if (! $user->hasRole($role->name)) {
    $user->addRole($role);
}

$user->flushCache();

// PROVE IT, rather than report success. A grant that the cache hides is the failure mode this
// script is most likely to have.
$fresh = User::where('email', $email)->first();
$fresh->flushCache();

$bad = 0;

foreach ($NAMES as $name) {
    $ok = $fresh->isAbleTo($name);
    printf("  %-36s %s\n", $name, $ok ? 'ok' : 'NOT GRANTED');
    $bad += $ok ? 0 : 1;
}

printf("\n%s: %d of %d finance permissions active (type=%s, store=%s)\n",
    $email, count($NAMES) - $bad, count($NAMES), $fresh->type, $fresh->current_store);

exit($bad ? 1 : 0);
