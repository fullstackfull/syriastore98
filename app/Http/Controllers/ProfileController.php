<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * product's own AND surfaced a **Delete Account** control for destroy() below, which is a
     * bare `$user->delete()` with no store, order or subscription cleanup. That is a product and
     * data-integrity decision, not a rendering repair, so it is left to the owner.
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
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
