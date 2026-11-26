<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GAP-242 / P4-F115 — `deletereview/{id}` DELETED ANY STORE'S REVIEW FOR ANY TOKEN HOLDER.
 * =============================================================================================
 *
 * `Api\DashboardController::DeleteReview()` was six lines and carried **no predicate of any kind**:
 *
 *     public function DeleteReview($id)
 *     {
 *         $testimonial = Testimonial::find($id);
 *         if ($testimonial) { $testimonial->delete(); ... }
 *     }
 *
 * No tenant predicate, no ownership predicate, no permission gate. `DELETE /api/admin/deletereview/N`
 * destroyed review N whoever asked.
 *
 * ---------------------------------------------------------------------------------------------
 * WHY NOTHING ELSE STOPPED IT — three separate controls that all look like they would.
 *
 *   `custom.auth` is NOT a role check. It resolves a Sanctum token and logs its owner into the
 *   guard matching the TOKENABLE CLASS (`app/Http/Middleware/CustomAuth.php:112-120`):
 *   `Customer`->`customers`, `User`->`web`, `DeliveryBoy`->`deliveryboy`. So a **shopper's** token
 *   and a **delivery driver's** token both pass it. The `admin` route prefix is a URL segment, not
 *   an authorization boundary, and `AdminApiLog` only logs.
 *
 *   `Testimonial` IS NOT A SCOPED MODEL. It is `use HasFactory, Cachable` only
 *   (`app/Models/Testimonial.php:12`) and is absent from `config/tenancy.php`'s `scoped_models`
 *   allowlist, so `StoreScope::isActiveFor()` short-circuits and the global scope adds **no
 *   predicate for any principal**. `Testimonial::find($id)` reaches every tenant's reviews. This is
 *   the same class of defect as the `Product` stock drain (S19): the model carries no scope, so a
 *   handler that relies on one is unprotected.
 *
 *   ITS OWN TWO SIBLINGS HAVE THE PREDICATE IT LACKED. `UpdateReview` resolves the store from the
 *   caller (`resolveOwnedStore()`, defined at :5155) and then keys the lookup on it
 *   (`Testimonial::where('id',...)->where('store_id', $store->id)`, :4125-4127); `AddReview` does
 *   the same before writing. Only the deleter was bare — so this is a gap in one handler of three,
 *   not a design the platform took.
 *
 * ---------------------------------------------------------------------------------------------
 * THE FIX DERIVES THE STORE FROM THE REVIEW, NOT FROM REQUEST INPUT, AND THAT IS FORCED BY THE
 * SHIPPED APP.
 *
 * The sibling shape — `resolveOwnedStore($request->store_id)` — is not available here, because
 * **the shipped app sends no `store_id` and no body at all**:
 *
 *     deleteReviewData(String reviewId) async {
 *       String endPoint = API.deleteReviewUrl + reviewId;
 *       var response = await NetworkHttps.deleteRequest(endPoint);
 *     }
 *     -- StoreSyria_app98/lib/config/repository/review_repository.dart:29-33
 *
 * (The commented-out block directly above it at :22-25 *did* pass `store_id`, which is presumably
 * why the server handler reads like it expects one. The live method does not.) A fix that required
 * `$request->store_id` would therefore refuse every real delete in the shipped app — a user-visible
 * regression dressed as a security fix, and the app cannot be redeployed alongside the server.
 *
 * So the predicate runs the other way: load the review, take **its** `store_id`, and ask
 * `resolveOwnedStore()` whether the caller owns that store. Identical authorization outcome, no new
 * request input, and no client change. A review whose own `store_id` is null resolves no store and
 * is refused — correct, because nobody can demonstrate ownership of it.
 *
 * NO PERMISSION GATE IS ADDED, deliberately. Neither `AddReview` nor `UpdateReview` has one, so
 * adding one here would invent a rule for one handler of three and could refuse staff the other two
 * admit. The ownership predicate is the defect; the missing permission family is recorded as an
 * owner question (`OWNER-DECISION-PACKAGE.md`), not decided here.
 */
class AdminApiReviewDeletionTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /* `category_id` carries a FOREIGN KEY to `categories` (the create migration, :27) and defaults
     * to 0, so it is passed explicitly as null rather than left to the default. */
    private function makeReview(Store $store): Testimonial
    {
        return Testimonial::forceCreate([
            'category_id' => null, 'product_id' => null, 'rating_no' => 5,
            'title' => 'Five stars', 'description' => 'Review of ' . $store->id,
            'username' => 'A shopper', 'status' => 1, 'store_id' => $store->id,
        ]);
    }

    private function makeShopper(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'F115',
            'email' => 'f115-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function makeDriver(User $merchant, Store $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver F115',
            'email' => 'f115-driver-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'contact' => '0100000000',
            'type' => 'deliveryboy', 'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
    }

    private function deleteReview(User $actor, int $reviewId)
    {
        $bearer = $actor->createToken('app')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->deleteJson('/api/admin/deletereview/' . $reviewId);
    }

    /** Raw, because `Testimonial` is unscoped — but the scope could be added, and then a plain
     *  `find()` would answer against whichever tenant resolved rather than against the table. */
    private function reviewSurvives(int $reviewId): bool
    {
        return Testimonial::withoutGlobalScopes()->where('id', $reviewId)->exists();
    }

    /** THE PREMISE, measured rather than assumed: the model really has no tenant scope. */
    public function test_the_testimonial_model_carries_no_tenant_scope(): void
    {
        $this->assertNotContains(
            Testimonial::class,
            config('tenancy.scoped_models', []),
            'Testimonial is now in scoped_models, so the global scope may be carrying the '
            . 'isolation this test attributes to the handler predicate. Re-measure before trusting '
            . 'the cases below.'
        );
    }

    /**
     * THE DEFECT: a SHOPPER's token destroys a merchant's review.
     *
     * A customer is the most ordinary principal on the platform — registration is open — and their
     * token passes `custom.auth` into the `customers` guard. Nothing downstream looked at what they
     * were.
     */
    public function test_a_shopper_token_cannot_delete_a_stores_review(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $review = $this->makeReview($store);

        $shopper = $this->makeShopper($store);
        $bearer = $shopper->createToken('app')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->deleteJson('/api/admin/deletereview/' . $review->id);

        $this->assertNotSame(500, $response->status(), 'the refusal crashed');

        $this->assertTrue($this->reviewSurvives($review->id),
            'a SHOPPER\'S token deleted a merchant\'s product review. `custom.auth` admits a '
            . 'Customer token (it logs the owner into the guard matching the tokenable class), the '
            . '`admin` URL prefix is not an authorization boundary, and Testimonial is not a scoped '
            . 'model -- so the bare `Testimonial::find($id)->delete()` was the only control, and it '
            . 'is not one.');
    }

    /**
     * AND A DELIVERY DRIVER's. Driven separately and on purpose: a `Customer` token and a
     * `DeliveryBoy` token land in DIFFERENT guards, so a fix that keyed on `auth('customers')`
     * would refuse the shopper above and still admit this one. CLAUDE.md records that a
     * customer-token probe alone cannot test this family.
     */
    public function test_a_delivery_driver_token_cannot_delete_a_stores_review(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $review = $this->makeReview($store);

        $driver = $this->makeDriver($merchant, $store);
        $bearer = $driver->createToken('app')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->deleteJson('/api/admin/deletereview/' . $review->id);

        $this->assertNotSame(500, $response->status(), 'the refusal crashed');

        $this->assertTrue($this->reviewSurvives($review->id),
            'a DELIVERY DRIVER\'S token deleted a merchant\'s product review');
    }

    /**
     * AND ANOTHER MERCHANT'S — the cross-tenant case proper. This is the one a merchant would use
     * against a competitor: delete the five-star reviews off their catalogue.
     */
    public function test_a_merchant_cannot_delete_another_stores_review(): void
    {
        $victim = $this->makeMerchant();
        $victimStore = $this->makeStore($victim);
        $review = $this->makeReview($victimStore);

        $attacker = $this->makeMerchant();
        $attackerStore = $this->makeStore($attacker);
        $attacker->current_store = $attackerStore->id;
        $attacker->save();

        $response = $this->deleteReview(User::find($attacker->id), $review->id);

        $this->assertNotSame(500, $response->status(), 'the refusal crashed');

        $this->assertTrue($this->reviewSurvives($review->id),
            'a merchant deleted a DIFFERENT store\'s product review. Reviews are the storefront\'s '
            . 'social proof, so this is a competitor-facing destructive primitive, and it is the '
            . 'one case the two sibling handlers already refuse.');
    }

    /**
     * THE CONTROL, AND IT IS THE POINT OF THE SHAPE CHOSEN: the store's own merchant still deletes
     * their own review, SENDING NO `store_id` — exactly as the shipped app does.
     *
     * If this fails, the fix refuses every legitimate delete in production. The row read is the
     * assertion, not the status: `ApiResponser::error()` answers HTTP **200** with `{status: 0}`,
     * so a 200 alone cannot tell "deleted" from "refused".
     */
    public function test_the_stores_own_merchant_still_deletes_their_own_review_without_sending_a_store_id(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();

        $review = $this->makeReview($store);

        $response = $this->deleteReview(User::find($merchant->id), $review->id);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, (int) $response->json('status'),
            'the store\'s own merchant was refused their own review, and the shipped app sends no '
            . 'store_id, so this is every real delete in production');

        $this->assertFalse($this->reviewSurvives($review->id),
            'the merchant got a success envelope and the review survived');
    }

    /** AND THE PLATFORM OPERATOR, who `isAbleTo()` and `creatorId()` both refuse by default. */
    public function test_the_platform_operator_still_deletes_a_review(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $review = $this->makeReview($store);

        $operator = $this->makePlatformAdmin();

        $response = $this->deleteReview(User::find($operator->id), $review->id);

        $this->assertFalse($this->reviewSurvives($review->id),
            'the platform operator was refused a review delete. There is no super-admin bypass in '
            . 'this codebase -- operator status is a string comparison on users.type -- so an '
            . 'ownership predicate that forgets the operator limb locks them out.');
    }

    /**
     * THE NULL-`store_id` REVIEW — a behaviour this file's docblock CLAIMED and no case pinned,
     * which the independent review caught. A documented behaviour with no test is a guess.
     *
     * `testimonials.store_id` is nullable and a null IS reachable: `TestimonialController:111`
     * writes `getCurrentStore()`, which answers null for a principal with an empty `current_store`
     * and no store of their own (`app/Helper/helper.php:883-893`). The new predicate resolves no
     * store for such a row and refuses it **for everyone, the operator included**.
     *
     * THAT IS THE RIGHT ANSWER AND IT MATCHES THE WEB, which is why it is asserted rather than
     * fixed: `TestimonialController:183-185` requires `! empty($testimonial->store_id)` before its
     * own delete. So an orphan review is undeletable from either surface — already true on the web
     * before this change, and failing closed is the correct direction for a row whose ownership
     * nobody can demonstrate. If the product ever wants orphans reapable, that is an owner decision
     * about a cleanup path, not a tenancy fix, and it should change both surfaces together.
     */
    public function test_a_review_with_no_store_is_refused_even_to_the_platform_operator(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();

        $orphan = $this->makeReview($store);
        /* Raw, and then flushed: Testimonial is `Cachable`, so an Eloquent read would serve the
         * pre-write row for the rest of the test (CLAUDE.md -- three measured false readings). */
        \Illuminate\Support\Facades\DB::table('testimonials')
            ->where('id', $orphan->id)->update(['store_id' => null]);
        (new Testimonial)->flushCache();

        $this->assertNull(
            Testimonial::withoutGlobalScopes()->where('id', $orphan->id)->value('store_id'),
            'premise: the fixture still carries a store_id, so this case is not measuring an orphan'
        );

        foreach ([
            'the store\'s own merchant' => User::find($merchant->id),
            'the platform operator' => User::find($this->makePlatformAdmin()->id),
        ] as $label => $actor) {
            $response = $this->deleteReview($actor, $orphan->id);

            $this->assertNotSame(500, $response->status(),
                'the orphan-review refusal crashed for ' . $label);

            $this->assertTrue($this->reviewSurvives($orphan->id),
                sprintf(
                    'a review with a NULL store_id was deleted by %s. Nobody can demonstrate '
                    . 'ownership of such a row, so the predicate must fail closed -- and the web '
                    . 'agrees (TestimonialController:183-185 requires a non-empty store_id before '
                    . 'its own delete).',
                    $label
                ));
        }
    }

    /** A review id that does not exist must not become a 500, and must not become a 401: the
     *  shipped app FORCE-CLEARS ITS SESSION on any 401, so a 401 here logs the merchant out. */
    public function test_an_unknown_review_id_is_refused_without_a_401_or_a_crash(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();

        $response = $this->deleteReview(User::find($merchant->id), 99999);

        $this->assertNotSame(500, $response->status());
        $this->assertNotSame(401, $response->status(),
            'an unknown review id answered 401, which force-clears the shipped app\'s session');
    }
}
