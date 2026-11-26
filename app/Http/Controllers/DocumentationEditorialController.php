<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Docs\DocumentationLibrary;
use App\Support\Docs\DocumentationRenderer;
use App\Support\Docs\DocumentationStatus;

/**
 * GAP-107 (P03-D-04) — THE DOCUMENTATION CENTRE'S EDITORIAL SURFACE.
 * =================================================================================================
 *
 * The gap: "working documentation exists; an editorial workflow does not". The workflow itself is
 * three front-matter states (`DocumentationStatus`), an enforcement in the library (only
 * `published` is served to a reader) and a written process
 * (`docs/development/phase-3/PHASE-03-DOCS-EDITORIAL-WORKFLOW.md`). This controller is the part a
 * person uses: WHERE IS EVERY ARTICLE IN THE PIPELINE, and CAN I READ THE DRAFT BEFORE I APPROVE IT.
 *
 * WHY THERE IS NO WRITE ACTION HERE, which is the first thing a reader of this file will look for.
 * The content is Markdown in the repository, and the transition between states IS the reviewed
 * commit that changes the `status` line — a record that already carries an author, a timestamp, a
 * diff and a reviewer. A button that rewrote `resources/docs/*.md` would write to a container
 * filesystem that the next deploy replaces, so the approval would be lost with no trace; that is a
 * worse workflow than none. `DocumentationStatus`'s docblock sets this out in full.
 *
 * -------------------------------------------------------------------------------------------------
 * THE GATE IS THE PLATFORM OPERATOR'S IDENTITY, AND EVERY PART OF THAT SENTENCE IS DELIBERATE.
 *
 *   - NOT `@can()` / `$user->can()`. This application runs Laratrust with
 *     `permissions_as_gates => false`, so both are ALWAYS FALSE and a gate written with them
 *     refuses everybody, operator included.
 *
 *   - NOT `isAbleTo('...')` ALONE. There is no super-admin bypass in this codebase: the platform
 *     operator holds no Laratrust role, so `isAbleTo()` refuses the one person who may use this
 *     screen. An authorization check here has to test the operator FIRST.
 *
 *   - NOT A PERMISSION NAME AT ALL, and this is the substantive choice rather than a workaround.
 *     A merchant creates their own roles through the product, so any permission name is
 *     substantially self-assignable; the documentation centre is the PLATFORM's own published
 *     content, not tenant content, and an unpublished article is the platform's unreleased work.
 *     `platform/analytics`, `platform/resources` and `platform/revenue` take `PlatformOperatorOnly`
 *     for exactly this reason and this screen joins them.
 *
 *     A consequence worth stating: the operator cannot DELEGATE documentation review to a
 *     non-operator user today. Doing so needs a real permission seeded in
 *     `Utility.php::$arrPermissions`, because a gate naming an unseeded permission refuses
 *     everybody — and seeding one is a product decision about who may read unreleased content.
 *     That is recorded as an owner question rather than guessed at.
 *
 *   - `UserPolicy::isOperator()` AND NOT A SECOND SPELLING OF IT. It is `strtolower(rtrim($type, ' '))`,
 *     matching MySQL's PAD SPACE: a TRAILING space is ignored by the engine and a LEADING one is
 *     not, so `' super admin'` is a non-operator to the database and must be one here too. A gate
 *     written with `trim()` admits that row.
 *
 * THE CHECK IS IN THE HANDLERS AS WELL AS ON THE ROUTES. `PlatformOperatorOnly` is on both routes;
 * this class checks again. Relying on route grouping alone has failed in this repository before
 * (the pattern SEC-F2 established), and a later edit that moves a route out of the group would
 * otherwise silently open the platform's unpublished content.
 *
 * 403 AND NEVER 401 for a signed-in non-operator: the shipped Flutter client maps any 401 to a
 * forced logout. The anonymous case belongs to `auth`, which runs first and redirects to sign-in.
 */
class DocumentationEditorialController extends Controller
{
    /** Every article, once, with what each language says about it. */
    public function index()
    {
        $this->refuseAnyoneButTheOperator();

        $rows = DocumentationLibrary::editorialRows();

        return view('platform.docs.editorial', [
            'rows'   => $rows,
            'counts' => $this->countsByStatus($rows),
            'states' => DocumentationStatus::ALL,
        ]);
    }

    /**
     * One article rendered as a reader would see it, published or not.
     *
     * THE SAME VIEW AS THE PUBLIC PAGE, on purpose: a reviewer approving an article must see what
     * will ship, not an approximation of it. The only difference is the notice, which the view
     * renders when `editorialNotice` is passed and the public controller never passes.
     */
    public function preview(string $category, string $article)
    {
        $this->refuseAnyoneButTheOperator();

        $locale = DocumentationLibrary::resolveLocale(app()->getLocale() ?: 'en');
        $row    = DocumentationLibrary::editorialArticle($locale, $category, $article);

        if ($row === null) {
            abort(404);
        }

        return view('platform.docs.article', [
            'locale'     => $locale,
            'article'    => $row,
            'isFallback' => $row['locale'] !== $locale,
            'html'       => DocumentationRenderer::html($row['body']),
            'toc'        => DocumentationRenderer::tableOfContents($row['body']),
            /* THE PUBLISHED projections, not the editorial ones: the sidebar, the related list and
               the pager are a READER's navigation, and offering a reviewer links to other people's
               unfinished drafts would make the preview a second, quieter documentation centre. */
            'related'    => DocumentationLibrary::related($locale, $row),
            'neighbours' => DocumentationLibrary::neighbours($locale, $row),
            'category'   => DocumentationLibrary::category($locale, $row['category']),
            'categories' => DocumentationLibrary::categories($locale),
            /* What makes this page honest. Shown for every state that is not published. */
            'editorialNotice' => DocumentationStatus::isPublic($row['status'])
                ? null
                : DocumentationStatus::label($row['status']),
        ]);
    }

    /**
     * @return array<string, int> how many articles sit in each state, by the English file's status
     *
     * BY THE ENGLISH FILE because English is the fallback locale and is therefore what a reader
     * gets when the Arabic is not published yet; the per-language columns on the screen are where
     * the Arabic state is read. An article with no English file at all is counted by whichever
     * locale has it, so the total always equals the number of rows.
     */
    private function countsByStatus(array $rows): array
    {
        $counts = array_fill_keys(DocumentationStatus::ALL, 0);

        foreach ($rows as $row) {
            $article = $row['locales'][DocumentationLibrary::LOCALES[0]]
                ?? array_values(array_filter($row['locales']))[0]
                ?? null;

            if ($article === null) {
                continue;
            }

            $counts[$article['status']] = ($counts[$article['status']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The platform operator and nobody else. See the class docblock for every part of this.
     *
     * `instanceof User` FIRST, AND IT IS NOT DECORATION. `UserPolicy::isOperator()` takes `$actor`
     * untyped and reads `$actor->type`, and `customers` HAS a `type` column -- measured, with
     * `'type'` in `Customer::$fillable` (`app/Models/Customer.php:35`), so a registration path
     * that mass-assigns request input decides its value. The remaining step, a `Customer` arriving
     * as `auth()->user()` on this route, needs the DEFAULT guard to be `customers`, which these
     * routes never make it: they sit in the `web` group, carry `auth` with no guard argument, and
     * do not carry `custom.auth`. So against a production request this is HARDENING and not a live
     * hole -- but it is load-bearing wherever the default guard IS `customers`, and that was
     * measured rather than argued: with this line removed and the customers guard made the default
     * (`actingAs($customer, 'customers')`, the shape CLAUDE.md records as hiding guard confusion),
     * the route's own `auth` middleware admitted the Customer and the handler answered HTTP 200
     * with the platform's unpublished content. `EditorialWorkflowGateTest` drives exactly that.
     */
    private function refuseAnyoneButTheOperator(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403, __('Permission denied.'));
        }
    }
}
