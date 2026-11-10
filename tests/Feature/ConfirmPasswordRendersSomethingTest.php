<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P2.5 — GET confirm-password rendered a BLANK PAGE, the second instance of the /profile/edit bug.
 * =================================================================================================
 *
 * `resources/views/auth/confirm-password.blade.php` was the last Breeze view in the auth folder:
 * `<x-guest-layout> … </x-guest-layout>`, which hands its body to the component as `$slot`.
 * `resources/views/layouts/guest.blade.php` contains ZERO references to `$slot` — it is the
 * vendored WorkDo layout and emits page content with `@yield('content')`. A layout that yields a
 * section never prints the slot it was given, so the whole form was discarded: HTTP 200, correct
 * chrome, nothing inside.
 *
 * ITS FIVE SIBLINGS WERE ALREADY CONVERTED. `login`, `register`, `forgot-password`,
 * `reset-password` and `verify-email` all use `@extends('layouts.guest')` with
 * `@section('content')`, which is why they render and this one did not. The repair makes it the
 * sixth, in the theme's own Bootstrap classes rather than the Tailwind ones it carried — this
 * application loads no Tailwind at all.
 *
 * AND THE ROUTE IS REAL. `routes/auth.php:52` registers `GET confirm-password` as
 * `password.confirm`, inside the `auth` group. Laravel's `password.confirm` middleware sends a
 * signed-in user here before a sensitive action, so a merchant could meet an empty page with no
 * way forward.
 *
 * WHAT THIS CASE ASSERTS, and why it is not just `assertOk()`. 200 was never the problem — the
 * blank page answered 200. So it asserts the FORM is in the body: the action, the field and the
 * submit. A chrome-only response passes a status check and fails these.
 *
 * The static sibling, tests/Unit/Ui/SlotLayoutViewsRenderTest, is what stops the NEXT one: it
 * fails on any view that passes a slot to a layout that cannot print one.
 */
class ConfirmPasswordRendersSomethingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_route_is_registered_under_its_own_name(): void
    {
        // The premise. If this route is ever removed the case below would pass by 404-ing, and
        // "the page is not blank because the page is gone" is not the claim being made.
        $this->assertTrue(Route::has('password.confirm'), 'Route [password.confirm] was dropped.');
    }

    public function test_confirm_password_renders_its_form_and_not_an_empty_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk();

        $body = $response->getContent();

        foreach ([
            'name="password"' => 'the password field',
            'action="' . route('password.confirm') . '"' => 'the form action',
            'type="submit"' => 'the submit control',
        ] as $needle => $what) {
            $this->assertStringContainsString(
                $needle,
                (string) $body,
                "confirm-password answered 200 without $what. That is the shape of the blank-page "
                . 'defect: `<x-guest-layout>` hands the body to a layout that emits '
                . "@yield('content') and never prints \$slot, so the chrome renders and the page "
                . 'does not. Check the view still uses @extends/@section.'
            );
        }
    }
}
