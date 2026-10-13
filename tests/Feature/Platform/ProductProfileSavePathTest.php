<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * THE MERCHANT PROFILE SAVE THAT THE PRODUCT ACTUALLY PERFORMS -- previously covered by
 * nothing at all, while three Breeze tests covered a controller the product never reaches.
 *
 * WHY THIS FILE EXISTS. docs/STABILIZATION-REGISTER.md recorded NEW-ROUTE-SHADOW-01 as a
 * LIVE defect: "ProfileController.php:37 Redirect::route('profile.edit') makes EVERY
 * successful merchant profile save write the record and then 500." That is measurably
 * wrong, and this file is the measurement that retires the claim rather than an argument
 * about it. The route shadow is real; its consequence is LATENT, not live.
 *
 * The product's own profile form is resources/views/users/profile.blade.php:47 --
 * Form::model($userDetail, ['route' => ['update.account'], 'method' => 'POST']) -- which
 * posts to routes/web.php:866, UserController@editprofile. That method ends in
 * `redirect()->back()` (UserController.php:396) and performs no route() lookup at all, so
 * the unregistered name profile.edit cannot affect it. ProfileController is never on this
 * path.
 *
 * The other two callers of route('profile.edit') are
 * resources/views/layouts/navigation.blade.php:37 and :83, and nothing extends or includes
 * layouts.navigation -- verified over resources/, themes/ and app/. ProfileController.php:19
 * also matches a grep for "profile.edit", but it is `view('profile.edit', ...)`, a VIEW
 * name, not a route name; counting it would have been the same class of error that an
 * unescaped dot made when "contact.us" matched contact-us and contact_us_banner_title.
 *
 * So the shadow is still worth fixing -- a declared route name that silently vanishes is a
 * trap, and p1e/agent2-security fixes it at routes/web.php:476 -- but it is not corrupting
 * merchant saves today, and nothing should be rushed on that basis.
 */
class ProductProfileSavePathTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        // NOT type 'super admin': app/Http/Middleware/XSS.php:27-48 redirects a super admin
        // to the updater whenever an on-disk migration filename is missing from the
        // migrations table, which would make every assertion below pass without the
        // controller ever running.
        return User::factory()->create([
            'type' => 'company',
            'email_verified_at' => now(),
        ]);
    }

    public function test_the_product_saves_a_merchant_profile_through_update_account(): void
    {
        $user = $this->merchant();

        $response = $this
            ->actingAs($user)
            ->from(route('profile'))
            ->post(route('update.account'), [
                'name'   => 'Renamed Merchant',
                'email'  => 'renamed@example.com',
                'mobile' => '96599887766',
            ]);

        $response->assertRedirect(route('profile'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Renamed Merchant', $user->name);
        $this->assertSame('renamed@example.com', $user->email);
        $this->assertSame('96599887766', (string) $user->mobile);
    }

    /**
     * THE ASSERTION THAT RETIRES THE "every save 500s" CLAIM. The save must complete with a
     * redirect, on a branch where route('profile.edit') genuinely does not resolve.
     */
    public function test_the_save_succeeds_even_though_profile_edit_is_not_registered(): void
    {
        $user = $this->merchant();

        $response = $this
            ->actingAs($user)
            ->from(route('profile'))
            ->post(route('update.account'), [
                'name'   => 'Still Works',
                'email'  => 'stillworks@example.com',
                'mobile' => '96500000000',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertSame(
            'Still Works',
            $user->refresh()->name,
            'The merchant profile save must persist. UserController@editprofile ends in '
            . 'redirect()->back() and never calls route(), so the shadowed profile.edit '
            . 'name cannot break it -- which is why NEW-ROUTE-SHADOW-01 is latent, not live.'
        );
    }

    /**
     * Pins the premise the two tests above rest on, so that if someone ever repoints the
     * product's form at ProfileController this file fails instead of silently testing a
     * path the product no longer uses.
     */
    public function test_the_products_own_form_posts_to_update_account_and_not_to_profilecontroller(): void
    {
        $blade = file_get_contents(base_path('resources/views/users/profile.blade.php'));

        // The raw literal, not a normalised form: the point is to notice if the form is
        // repointed, and a normaliser is one more thing that can silently stop matching.
        $this->assertStringContainsString(
            "array('route' => array('update.account')",
            $blade,
            'resources/views/users/profile.blade.php no longer posts to update.account. If the '
            . 'product now uses ProfileController, this whole file is testing the wrong path.'
        );

        $action = Route::getRoutes()->getByName('update.account')->getActionName();
        $this->assertStringContainsString(
            'UserController@editprofile',
            $action,
            'update.account must still be served by UserController@editprofile.'
        );
        $this->assertStringNotContainsString('ProfileController', $action);
    }

    public function test_a_profile_save_with_no_name_is_rejected_and_changes_nothing(): void
    {
        $user = $this->merchant();
        $original = $user->name;

        $response = $this
            ->actingAs($user)
            ->from(route('profile'))
            ->post(route('update.account'), [
                'name'  => '',
                'email' => 'attempted@example.com',
            ]);

        $response->assertRedirect(route('profile'));
        $response->assertSessionHas('error');

        $user->refresh();
        $this->assertSame($original, $user->name, 'A rejected save must not write the name.');
        $this->assertNotSame(
            'attempted@example.com',
            $user->email,
            'A rejected save must not write the email either. UserController@editprofile '
            . 'validates BEFORE it loads and mutates the row (UserController.php:370-394), '
            . 'so a partial write here would be a real defect.'
        );
    }

    public function test_an_anonymous_visitor_cannot_save_a_profile(): void
    {
        $response = $this->post(route('update.account'), [
            'name'  => 'Intruder',
            'email' => 'intruder@example.com',
        ]);

        // 302 to login, never 200 and never a write. Not 401: repo B's
        // lib/network/network.dart maps ANY 401 into a forced logout that clears
        // preferences, so no denial on this platform may answer 401.
        $response->assertStatus(302);
        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }
}
