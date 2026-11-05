<?php

/**
 * GRANT ARBITRARY PERMISSIONS TO THE DEV FIXTURE MERCHANT, so a gated screen can be rendered.
 * =================================================================================================
 *
 * THE GENERAL FORM of grant-dev-finance-permissions.php, which exists because render-matrix.cjs
 * signs in as merchant@example.test and a screen the fixture role cannot open answers with a
 * REDIRECT TO THE DASHBOARD, not an error -- so the harness records HTTP 200 with the wrong title
 * and a screenshot of the dashboard. That is the single most dangerous reading this project can
 * take, because it looks like evidence.
 *
 * MEASURED, P2.5-WAVE-13: `/customer/1` answered 200 titled "Dashboard" in all four combinations.
 * CustomerController::show() requires isAbleTo('Show Customer'); the dev role carries 50
 * permissions including `Manage Customer` but NOT `Show Customer`. A fixture gap, not a defect --
 * and not something to "fix" by weakening the controller.
 *
 * DEV FIXTURE ONLY. It mirrors grant-dev-finance-permissions.php exactly, including
 * Role::forceCreate (plain create() drops store_id) and the flushCache() at the end, which is NOT
 * optional: Role and Permission are among the few models that are NOT Cachable, but User is, so a
 * stale User row keeps the old role set for the rest of the process.
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
 *     php docs/development/phase-2.5/tools/grant-dev-screen-permissions.php \
 *         merchant@example.test "Show Customer"
 */
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

$email = $argv[1] ?? 'merchant@example.test';
$NAMES = array_slice($argv, 2);

if (! $NAMES) {
    fwrite(STDERR, "usage: grant-dev-screen-permissions.php <email> <Permission Name> [more...]\n");
    exit(2);
}

$user = User::where('email', $email)->first();

if (! $user) {
    fwrite(STDERR, "no such user: {$email}\n");
    exit(1);
}

$roleName = 'dev-screen-role-' . $user->id;
$role = Role::where('name', $roleName)->first();

if (! $role) {
    $role = Role::forceCreate([
        'name' => $roleName,
        'display_name' => 'Dev Screen Role',
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

// NOT OPTIONAL -- see the docblock. User is Cachable; without this the check below reads the
// pre-grant row and reports a false negative (or, worse, the server keeps serving the old set).
(new User)->flushCache();

$fresh = User::where('email', $email)->first();
$bad = 0;

echo "granted to {$email} (role {$roleName}):\n";
foreach ($NAMES as $name) {
    $ok = $fresh->isAbleTo($name);
    printf("  %-36s %s\n", $name, $ok ? 'ok' : 'NOT GRANTED');
    $bad += $ok ? 0 : 1;
}

printf("\n%s: %d of %d permissions active (type=%s, store=%s)\n",
    $email, count($NAMES) - $bad, count($NAMES), $fresh->type, $fresh->current_store);

exit($bad ? 1 : 0);
