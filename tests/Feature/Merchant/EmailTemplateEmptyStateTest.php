<?php

namespace Tests\Feature\Merchant;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P2.5 — the email template screen answered HTTP 500 when its table was empty.
 * =================================================================================================
 *
 * FOUND BY DRIVING IT. `GET /email_template_lang` was captured for the Phase 2.5 matrix on a
 * migrated dev database and came back **500 in all four** language/direction/theme combinations —
 * not an empty state, not a message, a white error page.
 *
 * `EmailTemplateController::emailTemplate` opens with
 *
 *     $emailTemplate = EmailTemplate::first();
 *     $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $emailTemplate->id)...
 *
 * and `first()` returns **null** on an empty table. A second dereference follows one branch
 * later: when a template exists but has no `en` row, `$currEmailTempLang->lang = $language`
 * writes to null.
 *
 * PRODUCTION IS NOT CURRENTLY AFFECTED and this test says so rather than overstating the find:
 * the production dump ships six platform templates and five language rows. The reachable states
 * the guard covers are a **freshly migrated environment before the addon seeder has run** and a
 * tenant whose templates have been removed — neither of which should be a white page.
 *
 * WHAT THIS ASSERTS, AND WHY NOT `assertOk()`. The screen cannot render without a template, so
 * "it renders" is the wrong bar; the bar is that it **fails as a redirect with a message** rather
 * than as a 500. Each case below asserts the status is not 5xx AND that the session carries an
 * error, because a redirect with no explanation is the same dead end with a nicer status code.
 */
class EmailTemplateEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        return User::factory()->create(['type' => 'admin', 'created_by' => 1]);
    }

    /**
     * An owner row that really exists.
     *
     * `email_templates.created_by` carries a FOREIGN KEY to `users(id)` -- it is one of the 15
     * entries in `UserDeletionSafety::CASCADE_DEPENDENCIES` -- and SQLite enforces it in this
     * suite (`config/database.php:39` leaves `foreign_key_constraints` on). Writing
     * `created_by => 1` into a fresh database therefore fails the INSERT rather than creating an
     * orphan, which is how this surfaced.
     */
    private function templateOwner(): User
    {
        return User::factory()->create(['type' => 'super admin', 'created_by' => null]);
    }

    public function test_premise_the_route_is_registered(): void
    {
        // Without this, a 404 would satisfy "not a 500" and the cases below would be vacuous.
        $this->assertTrue(
            Route::has('email_template'),
            'Route [email_template] was dropped, so these cases are measuring a missing route '
            . 'rather than a guarded one'
        );
    }

    public function test_an_empty_template_table_is_a_message_and_not_a_server_error(): void
    {
        $this->assertSame(
            0,
            EmailTemplate::query()->count(),
            'the fixture already holds templates, so this case cannot reach the null branch'
        );

        $response = $this->actingAs($this->merchant())->get(route('email_template'));

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'GET /email_template_lang still answers a server error when `email_templates` is '
            . 'empty. `EmailTemplate::first()` returns null and the next line reads its `id`.'
        );

        $this->assertNotNull(
            session('error'),
            'the screen now redirects instead of fatalling, but says nothing — which is the same '
            . 'dead end with a better status code'
        );
    }

    public function test_a_template_with_no_language_row_is_also_a_message(): void
    {
        // THE SECOND DEREFERENCE, one branch further on: a template exists, so the first guard
        // passes, and the `en` fallback is then null.
        EmailTemplate::query()->create([
            'name' => 'Order Created',
            'from' => 'Store Syria',
            'created_by' => $this->templateOwner()->id,
        ]);

        $this->assertSame(
            0,
            EmailTemplateLang::query()->count(),
            'a language row exists, so this case cannot reach the second null branch'
        );

        $response = $this->actingAs($this->merchant())->get(route('email_template'));

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'a template with no language row still fatals at '
            . '`$currEmailTempLang->lang = $language`'
        );

        $this->assertNotNull(session('error'), 'it redirects but explains nothing');
    }

    public function test_and_a_populated_table_still_renders_the_screen(): void
    {
        // THE OTHER DIRECTION. A guard that turned every request into a redirect would satisfy
        // both cases above.
        $template = EmailTemplate::query()->create([
            'name' => 'Order Created',
            'from' => 'Store Syria',
            'created_by' => $this->templateOwner()->id,
        ]);

        EmailTemplateLang::query()->create([
            'parent_id' => $template->id,
            'language' => 'en',
            'subject' => 'Order Created',
            'content' => '<p>Hello</p>',
        ]);

        $this->actingAs($this->merchant())->get(route('email_template'));

        /* ASSERTED ON THE GUARD, NOT ON THE RENDER, AND THE REASON MATTERS.
           =========================================================================================

           A populated table does NOT give a 200 in this suite, and that has nothing to do with
           either null guard. `partision/header.blade.php` reaches
           `HomeController::getThemeUrl()` through `getStoreById(getCurrentStore())`, which is null
           for a bare `User::factory()` row with no store -- the same cause as the project's
           DECLARED accepted failure `ProfileTest::test_profile_page_is_displayed`
           (.github/known-test-outcomes.json). Asserting a 200 here would be asserting against
           that known condition and would redden for a reason this file is not about.

           So the other direction is measured where it belongs: the controller got PAST both null
           branches, which is exactly what "the guard does not refuse the working case" means.
           Neither guard can fire without setting `error`.

           THE RENDER ITSELF IS PROVED ELSEWHERE, on evidence rather than on a status code: four
           captures on the dev fixture, which has a store, in all four language/direction/theme
           combinations -- evidence/web-closure-2026-11-10/. */
        $this->assertNull(
            session('error'),
            'the controller redirected with an error even though a template AND a language row '
            . 'exist, so one of the two null guards is refusing the working case'
        );
    }
}
