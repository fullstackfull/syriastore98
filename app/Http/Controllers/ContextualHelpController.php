<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Help\ContextualHelp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * PHASE 06 `SUPPORT-002` — THE WEB HALF OF «مساعدة سياقية»: THE HELP SCREEN.
 * =================================================================================================
 *
 * The header's help control resolves the merchant's current screen to a surface key and links here
 * with it. This screen prints that surface's documentation articles in the reader's language, and
 * the whole map when no surface is named.
 *
 * ONE SURFACE, NOT A POPOVER. `partision/header.blade.php` already records the decision for the
 * notification bell beside it — a dropdown is a second surface with its own focus trap, its own RTL
 * placement and its own dark-mode contrast to verify — and the same reasoning produced this screen
 * rather than a help panel on 334 pages.
 *
 * -------------------------------------------------------------------------------------------------
 * `surface` IS CALLER-SUPPLIED AND THAT IS SAFE HERE FOR A STATED REASON, NOT BY HABIT.
 *
 * CLAUDE.md's rule is that a predicate over DATA must be derived from the principal and never from
 * request input — four defects came from `$request->store_id`. This is not that: `surface` selects
 * between entries of a class CONSTANT whose values are filenames in `resources/docs/`, there is no
 * tenant row anywhere in the answer, and `ContextualHelp::articlesForSurface()` is a key lookup
 * that answers `[]` for anything it does not declare. The hostile shapes (`../../etc/passwd`,
 * `products/../orders`, an array) are driven in `ContextualHelpTest` and
 * `ContextualHelpScreenTest`.
 *
 * WHAT *IS* DERIVED FROM THE PRINCIPAL IS THE PRINCIPAL. `auth('web')->user()` and an
 * `instanceof User` test, checked in the handler as well as by the route — CLAUDE.md records twice
 * that a handler-level check behind an `abort()`ing middleware is invisible to an HTTP test, so
 * `ContextualHelpScreenTest` calls this handler DIRECTLY as a `Customer`.
 *
 * `instanceof User` AND NOT `auth('web')->check()`: registration is open and the `web` and
 * `customers` guards are separate sessions, so a visitor can hold both.
 */
class ContextualHelpController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $merchant = auth('web')->user();

        if (! $merchant instanceof User) {
            /* Keyed on a reason, not a status: there is no "not found" branch on this screen, so
               the test asserts the LANDED PATH rather than a 302 that two different facts share. */
            return redirect()->route('login');
        }

        $locale = ContextualHelp::normaliseLocale((string) app()->getLocale());

        /* Cast FIRST. `?surface[]=x` makes this an array, and an array reaching a string parameter
           is a TypeError and a 500 where a refusal was wanted. */
        $surface = $request->query('surface');
        $surface = is_string($surface) ? trim($surface) : '';

        $known = $surface !== '' && array_key_exists($surface, ContextualHelp::SURFACES);

        return view('help.index', [
            'surface' => $surface,
            /* THE SCREEN HAS TO TELL THREE SITUATIONS APART, which is why this is not just a list:
               a surface with help, a surface this build does not know (the app or a bookmark has
               drifted from the server), and no surface asked for at all. All three show the full
               map underneath; only the second is worth saying out loud. */
            'known' => $known,
            'articles' => $known ? ContextualHelp::articlesForSurface($surface, $locale) : [],
            'vocabulary' => ContextualHelp::vocabulary($locale),
            'locale' => $locale,
        ]);
    }
}
