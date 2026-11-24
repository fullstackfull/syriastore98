<?php

namespace App\Http\Controllers;

use App\Support\Metrics\PlatformAnalyticsFigures;
use Illuminate\Http\Request;

/**
 * THE PLATFORM OPERATOR'S CROSS-TENANT ANALYTICS SCREEN. P5-7 / ANA-001.
 *
 * ONE READ, NO WRITES, NO REQUEST INPUT. There is no store parameter, no date parameter and no
 * filter, so there is nothing a caller could supply and therefore nothing to validate. That is
 * deliberate: CLAUDE.md records that a tenant predicate built from caller-supplied input is not a
 * tenant check and has produced four separate defects in this codebase, and a screen that reads
 * EVERY tenant is the worst possible place to accept an id from a request.
 *
 * WHY THE GATE IS IDENTITY AND NOT A PERMISSION -- the one decision in this slice.
 *
 * The plan's rule for operator surfaces is "operator-first authorization, never bare isAbleTo()".
 * For a CROSS-TENANT screen, operator-first collapses to operator-ONLY, and the reason is specific
 * to this product rather than a matter of taste: a merchant creates their own roles
 * (`RoleController::store`, reachable with the `Create Role` permission that the shared `admin`
 * role carries for every self-registered merchant). So "holds permission X" is, for a merchant,
 * largely self-assignable. A permission name would therefore be the wrong kind of gate here even
 * though it is the right kind for `/analytics`, where the data is the caller's own either way.
 *
 * `config/superadminaccess.php` does hold 15 names a merchant may NOT grant themselves, filtered
 * server-side on both role write paths by `RoleController::filterGrantablePermissions`. It was
 * considered and not used: it is a GRANTABILITY control over plan, country, language, module and
 * currency administration, and widening it to cover reporting would change an unrelated surface to
 * express something `users.type` already says exactly.
 *
 * So the gate is `PlatformOperatorOnly`, attached in `routes/web.php` beside the merchant screen it
 * mirrors. It is reused rather than re-derived: it already compares `strtolower(trim(users.type))`
 * against `'super admin'` (there is NO super-admin bypass in Laratrust here, so `isAbleTo()` alone
 * refuses the operator), it defers the anonymous case to `auth` rather than answering it, and it
 * answers 403 and never 401 -- repo B's `lib/network/network.dart` maps any 401 to a forced logout
 * that clears stored preferences.
 *
 * NO STORE NEEDS TO BE SELECTED, which is the one way this screen differs from `/analytics`.
 * `getCurrentStore()` returns the hardcoded literal 1 for a `super admin`, so the merchant screen
 * has to refuse an operator who has chosen nothing -- otherwise it would present one particular
 * merchant's figures as "the" figures. This screen never calls it: it is explicitly the view across
 * all stores, so there is nothing to select and nothing to refuse.
 */
class PlatformAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        return view('platform.analytics', [
            'figures' => PlatformAnalyticsFigures::forPlatform(),
        ]);
    }

    /**
     * P5-8 — THE SAME FIGURES AS A FILE, WITH EVERY DISCLOSURE ATTACHED TO ITS OWN ROW.
     *
     * Same figures class, same arguments, no second query: the screen and the file cannot disagree
     * about a number. `FigureCsv` makes the period and the limits COLUMNS rather than a footnote,
     * which matters more here than anywhere else in the product -- the money block is a set of
     * per-currency amounts that must never be added together, and a spreadsheet is exactly the tool
     * that invites someone to add them. So the sentence saying so is repeated on every currency row.
     *
     * SAME GATE AS THE SCREEN. `PlatformOperatorOnly` is attached to this route in `routes/web.php`
     * beside `index`'s. An export route with a weaker guard than the screen it exports is a classic
     * way to lose a control, and this one would lose it across every tenant at once.
     */
    /**
     * THE BREAKDOWN LABELS, kept beside the figure map in `export()` and derived from nothing --
     * it is the file's breakdown set, as `AnalyticsExportRows::breakdownLabels()` is the
     * merchant's. Its labels are the SAME strings the screen's headings use, so the file and the
     * page cannot name the same breakdown differently.
     *
     * @return array<string, string>
     */
    public static function breakdownLabels(): array
    {
        return [
            'devices' => __('Devices'),
        ];
    }

    public function export(Request $request)
    {
        $figures = PlatformAnalyticsFigures::forPlatform();

        /*
         * THIS MAP IS THE FILE'S FIGURE SET, and `FigureCsv::rows()` iterates IT rather than
         * `$figures` -- so a figure the screen renders and this map omits is dropped from the
         * download in silence, with no error anywhere. That has now happened twice in this
         * codebase: `AnalyticsExportRows::breakdownLabels()`'s own comment records the campaign
         * breakdown shipping to the SCREEN and not to the FILE, and `retention` repeated it here,
         * added by the commit that put *Repeat purchase* on the operator screen.
         *
         * `FigureExportTest::test_the_platform_file_carries_every_figure_the_platform_screen_shows`
         * now fails when this map and `PlatformAnalyticsFigures::forPlatform()` disagree. It
         * derives its expectation from the figure source rather than from a second hard-coded
         * list, so adding a figure to the screen and not to this map is a red test rather than a
         * quiet omission. The merchant side has had that guard all along; the operator side did
         * not, which is the whole reason this was missable.
         */
        $rows = \App\Support\Metrics\FigureCsv::rows($figures, [
            'stores' => __('Stores'),
            'orders' => __('Orders'),
            'buyers' => __('Buyers'),
            'retention' => __('Repeat purchase'),
        ]);

        foreach (\App\Support\Metrics\FigureCsv::currencyRows($figures['payments'], __('Payments collected')) as $row) {
            $rows[] = $row;
        }

        /*
         * THE BREAKDOWN LOOP, and the reason it is a LOOP OVER A MAP rather than one hand-added
         * call is the three-time history the comment above records. `FigureCsv::rows()` skips any
         * figure with no `value` key, and a breakdown has none -- so a breakdown added to that map
         * is dropped in silence, which is the same shape as the three omissions already recorded
         * on this screen and its merchant twin.
         *
         * `FigureCsv::breakdownRows()` already existed and `AnalyticsExportRows::build()` already
         * looped it for the merchant file; the operator export was the one that never called it.
         * A correction worth recording: an earlier note of mine claimed the renderer itself was
         * breakdown-blind and had to be built. It was not -- only this handler was missing.
         *
         * `breakdownRows()` emits its own "Not available" row for an empty breakdown, so a
         * platform with no visits still produces a line rather than a hole in the file.
         */
        foreach (self::breakdownLabels() as $key => $label) {
            if (isset($figures[$key])) {
                foreach (\App\Support\Metrics\FigureCsv::breakdownRows($figures[$key], $label) as $row) {
                    $rows[] = $row;
                }
            }
        }

        return response(\App\Support\Metrics\FigureCsv::render($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="platform-analytics.csv"',
        ]);
    }
}
