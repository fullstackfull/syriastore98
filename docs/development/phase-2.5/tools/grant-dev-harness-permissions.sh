#!/bin/sh
#
# THE PERMISSIONS THE WEB CAPTURE HARNESS NEEDS, and nothing else.
# =================================================================================================
#
# `render-matrix.cjs` signs in as merchant@example.test. A merchant screen this application refuses
# answers with `redirect()->back()`, NOT an error -- so the harness follows the redirect, records
# HTTP 200, and photographs wherever it landed. P2.5-WEB-FINAL hit this on nine routes at once:
# every one reported "ok" with the title "Store Syria - Dashboard", and the run produced only
# ELEVEN distinct images from seventeen. `grant-dev-screen-permissions.php` says it best --
# "the single most dangerous reading this project can take, because it looks like evidence".
#
# This script exists so a fresh container is one command away from a harness that photographs
# screens instead of redirects, and so the list is a reviewable artefact rather than thirteen
# commands somebody has to rediscover from a transcript.
#
# ------------------------------------------------------------------------------------------------
# SCOPE, AND WHY THIS IS NOT A PRIVILEGE ESCALATION
#
#   * It writes ONLY to database/p2dev.sqlite, the gitignored DEV FIXTURE. The three variables are
#     set on the command itself, never exported -- exporting them points the TEST SUITE at this
#     file and the suite migrates it clean, which has destroyed the fixture twice (serve-dev.sh
#     carries the full account).
#   * It grants to merchant@example.test only. That account exists in no other environment.
#   * Every permission below is one a REAL merchant already holds through the shared `admin` role.
#     The fixture was missing them; that is a fixture gap, not a product decision, and the fix
#     belongs in the fixture rather than in a controller.
#   * NOTHING HERE WEAKENS A GATE. No controller changed, no isAbleTo() was removed, no role was
#     given a wildcard. If a screen refuses after this, it is refusing correctly.
#
# MINIMUM NECESSARY. Each line names the ONE permission its controller's `index()` gates on, and
# the screen it unblocks. Nothing was added "while we are here" -- the list was built by reading
# each controller and adding only what its own guard asks for:
#
#   Manage Deliveryboy       /deliveryboy      DeliveryBoyController::index
#   Manage Menu              /menus            MenuController::index
#   Manage Page              /pages            PageController::index
#   Manage Blog              /blog             BlogController::index
#   Manage Blog Category     /blog-category    BlogCategoryController::index
#   Manage Contact Us        /contacts         ContactController::index
#   Manage Newsletter        /newsletter       NewsletterController::index
#   Manage Shipping Class    /shipping         ShippingController::index
#   Manage Shipping Zone     /shipping-zone    ShippingZoneController::index
#   Manage Shipping          (shipping method surfaces)
#   Manage Tax               /taxes            TaxController::index
#   Manage Support Ticket    /support_ticket   SupportTicketController::index
#   Manage Stock Report      /stock-report/*   row-payload endpoints
#
# ONE OF THEM IS NOT A PERMISSION. `TaxController::index` requires `Manage Tax` AND the store
# setting `taxes == 'on'`; with the permission alone it still redirects. That setting is written
# below, for the same store, through Eloquent -- `getAdminAllSetting()` filters on `created_by`,
# so a row written with the wrong owner is invisible to the application and the screen keeps
# refusing while the database plainly shows the row.
#
# usage:  docs/development/phase-2.5/tools/grant-dev-harness-permissions.sh
#
set -e

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
FIXTURE="$ROOT/database/p2dev.sqlite"

[ -f "$FIXTURE" ] || { echo "fixture missing: $FIXTURE" >&2; exit 1; }

cd "$ROOT"

# IDEMPOTENT BY CONSTRUCTION. `grant-dev-screen-permissions.php` attaches straight to the role and
# throws `UNIQUE constraint failed: permission_role` on a permission the role already holds -- so a
# second run of this script would abort halfway and leave a reader unsure what had applied. The
# shared tool is used by other waves and is not changed here; instead this asks the database which
# of the thirteen are MISSING and passes only those. With nothing missing it calls nothing.
MISSING=$(DB_CONNECTION=sqlite DB_DATABASE="$FIXTURE" CACHE_STORE=file php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$wanted = [
    "Manage Deliveryboy", "Manage Menu", "Manage Page", "Manage Blog", "Manage Blog Category",
    "Manage Contact Us", "Manage Newsletter", "Manage Shipping Class", "Manage Shipping Zone",
    "Manage Shipping", "Manage Tax", "Manage Support Ticket", "Manage Stock Report",
    // Added 2026-11-10. `/manage-language` answered with a `redirect()->back()` in all four
    // combinations, which the harness correctly reported as landing on the previous page.
    // `LanguageController::manageLanguage` (:245) gates on `Manage Language`.
    //
    // CHECKED BEFORE GRANTING, because granting a permission a merchant does not really have
    // would verify a screen no merchant can open. `Utility::addNewData()` builds the permission
    // list for the `admin` role -- the merchant -- at :2814-3076, and `Manage Language` is in
    // it (:2908). The super-admin set is a separate list further down (:3295-3317). So this is
    // a merchant surface and the fixture was simply missing its own guard.
    "Manage Language",
];

$u = App\Models\User::where("email", "merchant@example.test")->first();
if (! $u) { fwrite(STDERR, "no merchant@example.test in the fixture\n"); exit(1); }

foreach ($wanted as $p) {
    if (! $u->isAbleTo($p)) { echo $p, "\n"; }
}
')

if [ -n "$MISSING" ]; then
  echo "granting $(printf '%s\n' "$MISSING" | wc -l) missing permission(s):"
  # shellcheck disable=SC2086
  printf '%s\n' "$MISSING" | tr '\n' '\0' | xargs -0 -r \
    env DB_CONNECTION=sqlite DB_DATABASE="$FIXTURE" CACHE_STORE=file \
      php docs/development/phase-2.5/tools/grant-dev-screen-permissions.php merchant@example.test
else
  echo "all thirteen permissions already active; nothing to grant."
fi

# The setting TaxController wants on top of its permission.
DB_CONNECTION=sqlite DB_DATABASE="$FIXTURE" CACHE_STORE=file php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::where("email", "merchant@example.test")->first();
if (! $u) { fwrite(STDERR, "no merchant@example.test in the fixture\n"); exit(1); }
App\Models\Setting::updateOrCreate(
    ["name" => "taxes", "store_id" => 1, "created_by" => $u->creatorId()],
    ["value" => "on"]
);
echo "setting taxes=on (created_by=", $u->creatorId(), ")\n";
'

# 79 of the 95 models are Cachable and the file cache was populated before these writes, so a
# stale User row would keep the old role set for every later request.
rm -rf storage/framework/cache/data/* 2>/dev/null || true

echo
echo "dev fixture ready for render-matrix.cjs."
echo "VERIFY, do not assume: a route that still answers with the title 'Store Syria - Dashboard'"
echo "is still being refused, and its capture is of the dashboard."
