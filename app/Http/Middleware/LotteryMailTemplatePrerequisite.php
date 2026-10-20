<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-20 / #67 item 4 -- a LEGITIMATE own-store lottery draw returns HTTP 500, AFTER it has
 * written the order and the `lottery_customers` rows.
 *
 * LotteryResultController:156-157:
 *
 *     $FailTemplate = EmailTemplate::where('name', 'Lottery Fail')->where('created_by', $user->id)->first();
 *     $FailEmailTemplate = UserEmailTemplate::where('user_id', $user->id)
 *         ->where('template_id', $FailTemplate['id'])->first();
 *
 * With no such template, `$FailTemplate['id']` is an array offset on null -- a PHP warning, which
 * Laravel's error handler promotes to `ErrorException`. Measured at HTTP 500 against a draw that
 * was otherwise entirely valid: the merchant's own store, their own ticket, their own permission.
 * The writes at :139 and :148 have already committed, so the merchant is left with an error page
 * and no way to tell whether the draw took.
 *
 * WHY AN ORDINARY MERCHANT HAS NO SUCH TEMPLATE. This is sharper than #67 recorded it, and it is
 * the part that makes the defect ordinary rather than an edge case.
 * `LotteryPrizesController::EmailTemplateDefaultData()` is the only code that creates
 * `Lottery Win`, `Lottery Fail` and `Lottery Remainder`, and it is called from exactly ONE place:
 * `LotteryemailTemplate()` at :315, the lottery mail-settings SCREEN, behind
 * `Manage Lottery Mail Setting`. Sibling modules wire the identical call into a ViewComposer so it
 * runs on every render -- `SpinToWinReward/src/app/Providers/ViewComposer.php:42`, and
 * `PartialPayments` likewise. LotteryPrizes has no such wiring. So a merchant who enables the
 * module, configures a lottery, sells tickets and draws a winner without ever opening that one
 * screen has no template at all.
 *
 * WHAT THIS MIDDLEWARE DOES, AND THE LINE IT DOES NOT CROSS. It creates the authenticated
 * merchant's own `email_templates` rows before the draw runs, so `:157` finds a row and takes an
 * integer offset.
 *
 * IT REIMPLEMENTS HALF OF `EmailTemplateDefaultData()` RATHER THAN CALLING IT, and saying
 * "it calls the module's own creator" -- as an earlier draft of this docblock did -- was wrong.
 * That method is a `public static` on a CONTROLLER and reads `Auth::user()->id` internally
 * (LotteryPrizesController:191), so calling it from middleware would couple this guard to a
 * vendored controller's static surface and to the ambient guard at once. What is reproduced is its
 * `email_templates` half (:182-201), with the same three names, the same owner column and the same
 * default `from`. Its `email_template_langs` half (:270-287) is NOT reproduced, and that is
 * harmless: that loop iterates the user's templates BY NAME rather than only the ones just
 * created, so a merchant who later opens the mail-settings screen still gets langs for these rows
 * -- and that screen, which 500s on its own first visit today (`$emailTemplate` is read at :313
 * before the creation at :315 and dereferenced at :318), starts working on the FIRST visit once
 * these rows exist.
 *
 * IT DELIBERATELY DOES NOT CALL `userTemplateDefaultData()`, the module's companion that creates
 * the `user_email_templates` join rows, even though `LotteryemailTemplate()` calls both. Those
 * join rows are what `if ($FailEmailTemplate)` tests to decide whether to SEND mail. Creating them
 * here would start sending lottery-loser email from a merchant who never configured a template --
 * a behaviour change, and an outbound one. Without them `$FailEmailTemplate` stays null, the `if`
 * is skipped exactly as it is today, and the only thing that changes is that the request does not
 * 500. That is the whole intended effect.
 *
 * WHERE THIS SITS IN THE STACK, because it decides whether the write can be abused. Resolved order
 * for `POST lotteryresult`: StartSession -> **Authenticate** -> ValidateCsrf -> SubstituteBindings
 * -> the five earlier guards -> **LotteryDrawOwnershipGuard** -> this -> **PlanModuleCheck**.
 * `Illuminate\Foundation\Http\Kernel::$middlewarePriority` maps `AuthenticatesRequests` but
 * neither `PlanModuleCheck` nor any of the appended guards, so the appended ones keep their
 * registration order. Two consequences, and the two that matter are the safe ones:
 *
 *   - `auth` runs BEFORE this, so there is no anonymous write.
 *   - `LotteryDrawOwnershipGuard` runs BEFORE this, so a draw refused for ownership or permission
 *     creates no template rows.
 *
 * `PlanModuleCheck` runs AFTER, so a merchant whose plan no longer carries LotteryPrizes gets
 * three template rows written for a request that is then refused. Own tenant, own `created_by`, no
 * cross-tenant effect, and the rows are ones the module would create anyway -- cosmetic, and left
 * alone rather than reordering a middleware stack for it. Appending in this order is what makes
 * the first two true; prepending this guard would have broken both.
 *
 * A MIDDLEWARE THAT WRITES A ROW IS UNUSUAL, so the reasoning is recorded rather than assumed.
 * The alternative host-side vehicles were considered and are worse: the null is inside vendored
 * code, which `CLAUDE.md` forbids editing, and no Policy, FormRequest or query scope sits between
 * `:156` and `:157`. The row created is the merchant's OWN notification template, with the same
 * name, owner and default content the module would create the moment they opened its settings
 * screen -- so this moves the creation earlier, it does not invent data. It is guarded by an
 * existence check, so a merchant who already has the template sees one extra `count` query.
 */
class LotteryMailTemplatePrerequisite
{
    private const TARGET_ACTION =
        'Workdo\LotteryPrizes\app\Http\Controllers\LotteryResultController@store';

    /**
     * Exactly the names `EmailTemplateDefaultData()` creates, in its order.
     */
    private const TEMPLATES = ['Lottery Win', 'Lottery Fail', 'Lottery Remainder'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route || ltrim((string) $route->getActionName(), '\\') !== ltrim(self::TARGET_ACTION, '\\')) {
            return $next($request);
        }

        // `auth()` WITH NO GUARD NAME IS DELIBERATE HERE, and it is the opposite of the sibling
        // guards' rule -- `LotteryDrawOwnershipGuard` insists on an explicit guard name because
        // `auth()` reads the MUTABLE default guard, which can hold a Customer. The reason it is
        // right here is that the handler reads the SAME resolver
        // (LotteryResultController:90 `$user = auth()->user()`), so matching it guarantees the row
        // this creates carries the id that `:156` then looks up. An explicit `auth('web')` could
        // diverge from the handler and the fix would silently not take.
        //
        // The `instanceof` is the hardening that makes that safe rather than merely intended: a
        // Customer or DeliveryBoy principal writes nothing. `email_templates.created_by` is a
        // foreign key to `users.id`, so a non-User id could otherwise only land by colliding with
        // a real user id -- bounded, but not a control.
        $user = auth()->user();

        if ($user instanceof User) {
            $this->ensureTemplatesExist((int) $user->id);
        }

        return $next($request);
    }

    /**
     * FAILING OPEN IS CORRECT HERE, and it is the opposite of what the ownership guards do.
     *
     * Those guards decide whether a request may proceed, so an error in them must refuse. This one
     * decides nothing: it removes a crash in the handler. If the write fails the request is no
     * worse off than it is today -- it reaches the same null dereference it reaches now -- whereas
     * refusing would turn a merchant's valid draw into a denial on the strength of a mail-template
     * row. So a failure here is swallowed and the request continues.
     */
    private function ensureTemplatesExist(int $userId): void
    {
        try {
            // THE READ IS RAW BECAUSE THE WRITE IS RAW, and the first version of this method got
            // that wrong in a way that MEASURED AT THREE EXTRA ROWS PER DRAW.
            //
            // `App\Models\EmailTemplate` is `Cachable`. The Eloquent builder's `pluck()` is cached
            // (laravel-model-caching Buildable.php:200-210 -> cachedValue() -> rememberForever),
            // and it caches an EMPTY result too, because the stored value is a
            // `['key' => ..., 'value' => ...]` collision wrapper and therefore never null -- so the
            // usual "rememberForever does not store null" escape does not apply. Only the ELOQUENT
            // builder's insert/update/delete flush that cache; `DB::table()` is a plain query
            // builder and flushes nothing, and the configured store is not taggable, so there is no
            // tag-level invalidation either.
            //
            // So an Eloquent read behind a raw write served the pre-write empty set: the SECOND
            // draw by the same merchant recomputed all three names as missing and inserted them
            // again. Measured: `email_templates` went 3 -> 6 across two draws in one request cycle.
            // Unbounded, on the table the mail-settings screen lists and that
            // `sendOwnerEmailTemplate:715` resolves with an unscoped `first()`.
            //
            // `EmailTemplate::create()` would flush, but on a non-taggable store `flushCache()`
            // flushes the WHOLE application cache -- a far worse trade for a template row. Reading
            // through the same query builder that writes is the one-statement fix.
            $existing = DB::table('email_templates')
                ->whereIn('name', self::TEMPLATES)
                ->where('created_by', $userId)
                ->pluck('name')
                ->all();

            $missing = array_values(array_diff(self::TEMPLATES, $existing));

            if ($missing === []) {
                return;
            }

            // `from` matches what EmailTemplateDefaultData() writes. The module reads it through
            // `env('APP_NAME')`, which is null once config is cached; `config('app.name')` is the
            // same value by the correct route.
            $from = (string) config('app.name');

            foreach ($missing as $name) {
                DB::table('email_templates')->insert([
                    'name' => $name,
                    'from' => $from,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Deliberately swallowed -- see the docblock above.
        }
    }
}
