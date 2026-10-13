<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORRECTED SCAFFOLD TEST. The assertion this file used to make was WRONG for this
 * product, and it is replaced here rather than deleted. Both changes are stated
 * explicitly, with the evidence that the old version was wrong.
 *
 * WHAT IT USED TO DO, and why that was wrong on two counts:
 *
 *   1. It had NO RefreshDatabase (the import was commented out on line 5 of the stock
 *      Breeze file). The request therefore ran against a database with no schema at
 *      all, and the measured failure was
 *        SQLSTATE[HY000]: no such table: users
 *        (SQL: select * from "users" where "type" = super admin limit 1)
 *      raised from app/Http/Middleware/SetLocate.php:45. That is a missing fixture,
 *      not a product result: the test could not reach the route it meant to exercise.
 *
 *   2. It asserted HTTP 200 on `/`. This product does not serve a page there to an
 *      unauthenticated visitor. MEASURED, with RefreshDatabase and no other fixture:
 *        GET /  ->  302, Location: http://localhost/login
 *      `/` is Route::any('/', [HomeController::class, 'Landing'])->name('start')
 *      (routes/web.php:382). Breeze's 200 came from the welcome page that shipped with
 *      the scaffold; this product replaced that route, so 200 asserted a page that does
 *      not exist here. Asserting 200 could only ever fail.
 *
 * WHAT IT NOW COVERS, which is strictly more than before: the root route is reachable,
 * boots the full web middleware stack (SetLocate included), and performs the product's
 * real guest redirect instead of erroring. The property "the application answers / " is
 * still covered -- it is now covered against the behaviour this product actually has.
 *
 * NOTE for whoever revisits this: `/` is reached WITHOUT seeding a super-admin row,
 * because the redirect is issued before any view renders. Pages that do render are a
 * different story and are still red on purpose -- see
 * tests/Feature/Auth/AuthenticationTest.php and the Platform suite for the two real
 * defects that block them.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_route_redirects_a_guest_to_the_login_screen(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    /**
     * Pinned separately from the redirect target: the point is that the root route does
     * not 500. A server error here would be invisible in an assertRedirect-only test if
     * the redirect were later replaced by an error page that happened to redirect.
     */
    public function test_the_root_route_does_not_raise_a_server_error(): void
    {
        $response = $this->get('/');

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'GET / returned a server error. The root route boots SetLocate, which reads '
            . 'the users table, and app/Helper/helper.php::get_file(); a 500 here usually '
            . 'means one of those two touched something absent.'
        );
    }
}
