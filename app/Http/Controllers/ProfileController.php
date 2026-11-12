<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\UserDeletionSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class ProfileController extends Controller
{
    /**
     * Send the merchant to the product's own profile screen.
     *
     * THIS USED TO `return view('profile.edit')` AND THE RESULT WAS A BLANK PAGE.
     *
     * `resources/views/profile/edit.blade.php` is Breeze markup -- `<x-app-layout>` ...
     * `</x-app-layout>` -- so its body is handed to the component as `$slot`.
     * `App\View\Components\AppLayout::render()` returns `view('layouts.app')`, and that file is
     * the WorkDo layout: it has ZERO references to `$slot` and emits page content through
     * `@yield('content')` (resources/views/layouts/app.blade.php:708). A layout that yields a
     * section never outputs the slot it was given, so the entire form was dropped on the floor --
     * silently, with HTTP 200 and a perfectly correct sidebar, header and footer around an empty
     * content area. Measured in all four language/direction/theme combinations by the Phase 2.5
     * route sweep; captures under
     * docs/development/phase-2.5/evidence/web-sweep-2026-11-10/after/_profile_edit__*.png.
     *
     * The product's profile screen is GET /profile (UserController@profile), which updates the
     * profile and changes the password. This URI exists only so that update()'s
     * `Redirect::route('profile.edit')` below resolves -- the NEW-ROUTE-SHADOW-01 repair -- and
     * nothing in the shipped UI links to it (its only callers are
     * resources/views/layouts/navigation.blade.php:37,83, a partial no view includes).
     *
     * Rendering the Breeze form instead would have added a second profile editor beside the
     * product's own AND surfaced a **Delete Account** control for destroy() below. When this
     * paragraph was written that method was a bare `$user->delete()` with no cleanup; the P0
     * repair in the same commit put `UserDeletionSafety::blockers()` in front of it, so it now
     * refuses rather than cascades. The decision stands all the same, for a different reason:
     * exposing a self-service account-deletion control is a product decision, and the guard means
     * the control would refuse every tenant owner who pressed it -- see the limitation in
     * docs/security/P0-USER-DELETION-CASCADE.md section 6.
     *
     * Pinned by tests/Feature/ProfileEditRendersSomethingTest.
     */
    public function edit(Request $request): RedirectResponse
    {
        return Redirect::route('profile');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    /**
     * SELF-DELETION, now fail-closed on data safety. Owner-approved.
     *
     * This route is self-target only -- no id is read from the request -- so there is no
     * authorization question to answer beyond the session and the password. The DATA SAFETY
     * question is the same one `UserController::destroy` must answer, and it is answered by the
     * same service, because a guard copied per door is how a guard and the delete it guards come
     * to ask different questions.
     *
     * WHAT CHANGED BESIDES THE GUARD. `Auth::logout()` used to run BEFORE `$user->delete()`, so a
     * refusal now would have left the merchant signed out of an account that still exists. The
     * logout moved after the delete, where it belongs: nothing is torn down until the delete has
     * actually happened.
     *
     * The refusal is a validation error on the same `userDeletion` bag the password error uses,
     * so the Breeze form -- if it is ever rendered again -- shows it where it shows the other.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $blockers = [];

        DB::transaction(function () use ($user, &$blockers) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $blockers = UserDeletionSafety::blockers($locked);

            if ($blockers !== []) {
                return;
            }

            $locked->delete();
        });

        if ($blockers !== []) {
            return Redirect::back()
                ->withErrors([
                    'password' => __('This account cannot be deleted because it still owns data.')
                        . ' ' . implode('; ', $blockers),
                ], 'userDeletion');
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
