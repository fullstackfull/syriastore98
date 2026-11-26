<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Platform\PlatformContent;
use App\Support\Platform\PlatformContentStore;
use Illuminate\Http\Request;

/**
 * GAP-104 (P03-C-05) — THE OFFICIAL WEBSITE'S CONTENT EDITOR.
 * =================================================================================================
 *
 * The gap: "the official website has no content-management capability… Changing a headline, an FAQ
 * answer or the legal text requires a code change, review, merge and deploy. There is no admin
 * screen for platform content." This is that screen. Its knock-on, which the gap singles out —
 * "the five legal documents and the contact details, which the owner must supply, can only be
 * published by a developer" — is what the owed fields in `PlatformContent` exist for.
 *
 * WHAT THIS SCREEN DOES NOT DO, so a reader is not left looking for it: it does not invent content.
 * Nine fields carry no built-in copy because the OWNER owes them — five legal documents and four
 * contact details — and this screen's job for those is to show that they are outstanding and give
 * the owner somewhere to put the approved text. There is no draft privacy policy, no specimen
 * address and no placeholder company detail anywhere in this change.
 *
 * -------------------------------------------------------------------------------------------------
 * THE GATE IS THE PLATFORM OPERATOR'S IDENTITY, AND EVERY PART OF THAT IS DELIBERATE. The same
 * reasoning as `DocumentationEditorialController`, which is the nearest precedent and was reviewed
 * and hardened for exactly these four traps:
 *
 *   - NOT `@can()` / `$user->can()`. Laratrust runs with `permissions_as_gates => false`, so both
 *     are ALWAYS FALSE and a gate written with them refuses everybody, operator included.
 *
 *   - NOT `isAbleTo('...')` ALONE. There is no super-admin bypass in this codebase: the platform
 *     operator holds no Laratrust role, so `isAbleTo()` refuses the one person who may use this
 *     screen. An authorization check here has to test the operator FIRST.
 *
 *   - NOT A PERMISSION NAME AT ALL, and that is the substantive choice rather than a workaround. A
 *     merchant creates their own roles through the product, so any permission name is substantially
 *     self-assignable — and the subject here is the PLATFORM's own public words, published at the
 *     company's address, including (once supplied) the five documents that bind the business. A
 *     self-assignable gate over that is brand impersonation with extra steps.
 *     `platform/analytics`, `platform/resources`, `platform/revenue` and `platform/documentation`
 *     all take `PlatformOperatorOnly` for the same reason and this screen joins them.
 *
 *     A consequence worth stating: the operator cannot DELEGATE official-site editing to a
 *     non-operator user today. Doing so needs a real permission seeded in
 *     `Utility.php::$arrPermissions` — a gate naming an unseeded permission refuses everybody —
 *     and seeding one is a product decision about who may speak for the company. Recorded as an
 *     owner question rather than guessed at.
 *
 *   - `UserPolicy::isOperator()` AND NOT A SECOND SPELLING OF IT. It is
 *     `strtolower(rtrim($type, ' '))`, matching MySQL's PAD SPACE: a TRAILING space is ignored by
 *     the engine and a LEADING one is not, so `' super admin'` is a non-operator to the database
 *     and must be one here too. A gate written with `trim()` admits that row.
 *
 * `instanceof User` FIRST. `UserPolicy::isOperator()` takes `$actor` untyped and reads
 * `$actor->type`, and `customers` HAS a `type` column with `'type'` in `Customer::$fillable`, so a
 * registration path that mass-assigns request input decides its value. `instanceof` short-circuits,
 * so `isOperator()` never reads `->type` off a non-`User` at all.
 *
 * THE CHECK IS IN EVERY HANDLER AS WELL AS ON EVERY ROUTE. `PlatformOperatorOnly` is declared on
 * all three routes; all three handlers check again. Relying on route grouping alone has failed in
 * this repository before (the pattern SEC-F2 established), and a later edit that moved one route
 * out of the group would otherwise hand the company's public words to a merchant.
 *
 * 403 AND NEVER 401 for a signed-in non-operator: the shipped Flutter client maps any 401 to a
 * forced logout that clears stored preferences. The anonymous case belongs to `auth`, which runs
 * first and redirects to sign-in.
 *
 * -------------------------------------------------------------------------------------------------
 * NOTHING HERE READS AN IDENTIFIER FROM REQUEST INPUT AND TRUSTS IT. The one route parameter is a
 * page name, and it is looked up in `PlatformContent::pages()` — a declared array — rather than
 * used to build a path, a query or a view name. Every submitted key is filtered against the
 * registry, and against THIS page's slice of it, so one screen's POST cannot rewrite content the
 * operator was not looking at. Every submitted locale is filtered against the two the official site
 * is published in.
 *
 * THERE IS NO TENANT IN THIS FILE AT ALL, and that is the point: the official website is the
 * platform's, not a store's. No `getCurrentStore()`, no `store_id` from input, nothing scoped. The
 * rows it writes carry `store_id` NULL for that reason — see `PlatformContentStore`.
 */
class PlatformContentController extends Controller
{
    /** Where every page stands: how much of its content is supplied, and what is still owed. */
    public function index()
    {
        $this->refuseAnyoneButTheOperator();

        $pages = [];

        foreach (PlatformContent::pages() as $slug => $page) {
            $fields = PlatformContent::fieldsFor($slug);
            $owed   = array_filter($fields, static fn (array $f) => ($f['owed'] ?? false) === true);

            $supplied = [];
            foreach (PlatformContent::LOCALES as $locale) {
                $supplied[$locale] = count(array_filter(
                    array_keys($fields),
                    static fn (string $key) => PlatformContent::isSupplied($locale, $key)
                ));
            }

            /* OWED AND STILL OUTSTANDING, per locale: the number the owner needs to see. A field is
               outstanding in a locale when nothing has been supplied FOR THAT LOCALE, because an
               approved English document and an approved Arabic one are two documents. */
            $outstanding = [];
            foreach (PlatformContent::LOCALES as $locale) {
                $outstanding[$locale] = count(array_filter(
                    array_keys($owed),
                    static fn (string $key) => ! PlatformContent::isSupplied($locale, $key)
                ));
            }

            $pages[$slug] = [
                'label'       => $page['label'],
                'route'       => $page['route'],
                'total'       => count($fields),
                'owed'        => count($owed),
                'supplied'    => $supplied,
                'outstanding' => $outstanding,
            ];
        }

        return view('platform.content.index', [
            'pages'   => $pages,
            'locales' => PlatformContent::LOCALES,
        ]);
    }

    /** One page's fields, in both languages. */
    public function edit(string $page)
    {
        $this->refuseAnyoneButTheOperator();

        $definition = PlatformContent::pages()[$page] ?? null;

        if ($definition === null) {
            abort(404);
        }

        return view('platform.content.edit', [
            'page'    => $page,
            'label'   => $definition['label'],
            'preview' => $definition['route'],
            'fields'  => PlatformContent::fieldsFor($page),
            'locales' => PlatformContent::LOCALES,
        ]);
    }

    /**
     * Save one page's fields.
     *
     * AN EMPTY FIELD MEANS "NO OVERRIDE", one rule stated once. For a field with built-in copy that
     * restores the copy, so a bad edit is undoable without a deploy — which is the defect this row
     * exists to remove, in reverse. For an owed field it means the content is still outstanding and
     * the page keeps saying so.
     *
     * THE MERGE IS PER LOCALE AND PER PAGE. The stored map for a locale is read, only the fields
     * belonging to THIS page are replaced in it, and the result is written back — so a form that
     * renders seven fields cannot blank the other forty-seven.
     */
    public function update(Request $request, string $page)
    {
        $this->refuseAnyoneButTheOperator();

        if (! array_key_exists($page, PlatformContent::pages())) {
            abort(404);
        }

        $fields   = PlatformContent::fieldsFor($page);
        $existing = PlatformContentStore::all();

        foreach (PlatformContent::LOCALES as $locale) {
            /*
             * `$request->input($locale)` AND A TYPE CHECK. The value is whatever was posted: a
             * string, a nested array, or absent. Anything that is not an array of scalars is
             * treated as "this locale was not submitted", which leaves its stored content alone
             * rather than wiping it.
             */
            $submitted = $request->input($locale);

            if (! is_array($submitted)) {
                continue;
            }

            $map = $existing[$locale] ?? [];

            foreach (array_keys($fields) as $key) {
                if (! array_key_exists($key, $submitted)) {
                    // Not on the form at all: leave whatever is stored.
                    continue;
                }

                $value = $submitted[$key];

                /*
                 * NULL IS AN EMPTIED FIELD, NOT A MISSING ONE, and this is the one line this
                 * capability does not work without. `ConvertEmptyStringsToNull` is GLOBAL
                 * middleware in this application -- measured from the resolved kernel, where it
                 * sits after `TrimStrings` -- so an operator who clears a textarea and saves
                 * POSTs `''`, which arrives here as `null`. Treating that as "not submitted"
                 * made every override permanent: the content could be changed without a deploy
                 * and never changed BACK without one, which is the defect this row exists to
                 * remove, in reverse. Driven by
                 * `PlatformContentPublishingTest::test_clearing_a_field_restores_the_built_in_copy`,
                 * which failed on exactly this before the line existed.
                 */
                if ($value === null) {
                    $value = '';
                }

                if (! is_string($value)) {
                    // An array or an object where a string belongs: refuse it, do not stringify.
                    continue;
                }

                $value = PlatformContent::sanitise($key, $value);

                if ($value === '') {
                    unset($map[$key]);
                } else {
                    $map[$key] = $value;
                }
            }

            PlatformContentStore::put($locale, $map);
        }

        PlatformContentStore::forget();

        return redirect()
            ->route('platform.site_content.edit', $page)
            ->with('success', __('The official website has been updated.'));
    }

    /**
     * The platform operator and nobody else. See the class docblock for every part of this.
     */
    private function refuseAnyoneButTheOperator(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403, __('Permission denied.'));
        }
    }
}
