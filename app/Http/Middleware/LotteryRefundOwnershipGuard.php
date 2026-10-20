<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-20 / #67 item 2 -- `refund_lottery_form_data` DELETES a `purchase_tickets` row keyed on
 * the posted `lottery_code` and the customer id, with no store predicate at all.
 *
 * LotteryPrizesController:870-871:
 *
 *     $lottery_setting = PurchaseTicket::where('lottery_code', $request['ticket_code'])
 *         ->where('customer_id', auth('customers')->user()->id)->first();
 *     $lottery_setting->delete();
 *
 * `PurchaseTicket` is a `Workdo\*` model, so it is not on `config/tenancy.php`'s `scoped_models`
 * and `StoreScope::isActiveFor()` short-circuits: the model adds NO predicate for anyone. The two
 * keys above are the whole authorisation.
 *
 * MEASURED, not read. Store A and store B, one customer registered in A:
 *
 *   POST /store-a/refund-lottery-form-data  {ticket_code: <store B's code>}
 *   -> HTTP 302 "Refund sucessfully", and store B's purchase_tickets row is GONE.
 *
 * WHY A CUSTOMER HAS A ROW IN ANOTHER STORE AT ALL, which is the step that makes this reachable:
 * `customers` is ONE session guard (`config/auth.php`), not one per store. A shopper signed in at
 * store A who walks into store B's storefront is still a populated `customers` principal there, so
 * `StoreLotteryPayment:613` stamps their store-A customer id onto a store-B ticket row. The refund
 * form on EITHER storefront then reaches BOTH rows, and `->first()` decides by primary key which
 * one is destroyed. Store B's merchant loses a sold ticket without store B's refund rules ever
 * running.
 *
 * AND THE UNMATCHED CODE IS A 500, measured at HTTP 500: `->delete()` on a null lookup. Any wrong
 * code, including a typo, is an error page rather than "no such ticket".
 *
 * THE PREDICATE IS THE TRAIT'S, DELIBERATELY. It asks whether the handler's own `whereIn` can
 * reach any row outside the store, rather than comparing the caller's two values -- which is the
 * only form that survives MySQL's `utf8mb4_0900_ai_ci` collation, where `abc-1` and `ABC-1` are
 * the same value to the database and different ones to PHP. SQLite's BINARY default hides that
 * completely; the proof is tests/Support/Security/bin/verify-lottery-code-collation-on-mysql.php.
 *
 * It is called through `noMatchedRowOutsideStore()` with the SLUG-RESOLVED store rather than
 * `noMatchedRowOutsideCallerStore()` with a `User`, because the caller here is a `Customer` and
 * `getCurrentStore()` answers a question about merchants. Same predicate, explicit store.
 *
 * LIMB 2 OF THAT PREDICATE ALSO REMOVES THE 500 FOR ORDINARY INPUT: it requires every requested
 * code to be accounted for by a row inside the store, so a code matching nothing is refused here
 * instead of reaching the null `->delete()`.
 *
 * "FOR FREE" WAS TOO STRONG, and adversarial review found the case that falsifies it. The guard
 * reads the code with `$request->input('ticket_code')`; the handler reads `$request['ticket_code']`,
 * which goes through `all()` -- and `all()` is
 * `array_replace_recursive($this->input(), $this->allFiles())`, so a FILE wins over a field of the
 * same name while `input()` excludes files entirely. The refund form is already
 * `enctype="multipart/form-data"` (theme/refund-lottery-form.blade.php:137), so a multipart POST
 * carrying both a text `ticket_code=MY-OWN-CODE` and a file part named `ticket_code` passes both
 * limbs on the text value, and the handler then matches on the uploaded file's pathname, finds
 * nothing, and 500s at `:871`.
 *
 * NOT FIXED, and the reasoning is deliberate rather than lazy: it is not an ownership bypass -- a
 * `/tmp/phpXXXX` pathname cannot equal an 8-character `lottery_code` -- and the caller inflicts
 * the 500 on themselves alone. Narrowing the guard to read through `all()` would make it agree
 * with the handler, but would also hand the guard an `UploadedFile` to validate, which is a worse
 * shape than the one it has. Recorded so the claim above is not read as broader than it is.
 *
 * The other input divergences were checked and are closed: there is no route parameter named
 * `ticket_code` on this route, so `Request::__get`'s route-parameter fallback cannot fire; a nested
 * array or JSON array fails `is_scalar` and is denied; JSON and form bodies both resolve through
 * `input()` identically; and `'0'` is accepted here but falsy in the handler's own
 * `if ($request['ticket_code'])`, which is the safe direction.
 *
 * ONE THING THIS GUARD CANNOT DO, and it is not silence: it cannot make the handler delete the
 * RIGHT row. The handler's query has no store filter, so with the guard in place it still runs
 * unscoped -- the guard's contribution is that no foreign row can match it, which makes the
 * unscoped `->first()` safe by elimination rather than by correction. The same limitation is
 * recorded on #64's guard as `test_KNOWN_LIMITATION_an_identical_code_string_in_another_tenant...`,
 * and the consequence is identical: a caller whose own code collides, under the database's
 * collation, with a foreign one is refused rather than served. Refusing the owner is the safe
 * direction, and it is asserted rather than left to be discovered.
 */
class LotteryRefundOwnershipGuard
{
    use ComparesRowOwnership;

    private const TARGET_ACTION =
        'Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController@refund_lottery_form_data';

    private const TABLE = 'purchase_tickets';

    private const COLUMN = 'lottery_code';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route || ltrim((string) $route->getActionName(), '\\') !== ltrim(self::TARGET_ACTION, '\\')) {
            return $next($request);
        }

        // THE HANDLER DEREFERENCES `auth('customers')->user()->id` WITH NO GUARD, so an anonymous
        // caller is a 500 today. The route carries no `auth` middleware, so that is reachable by
        // anyone. Refuse it here, which is both the security answer and the correct response.
        $customer = auth('customers')->user();

        if (! $customer) {
            return $this->deny($request, __('Please sign in to request a refund.'));
        }

        $code = $request->input('ticket_code');

        if (! is_scalar($code) || trim((string) $code) === '') {
            return $this->deny($request, __('Please select a ticket code.'));
        }

        $store = $this->resolveStore($request);

        if ($store === null) {
            return $this->deny($request, __('This ticket could not be refunded.'));
        }

        if (! $this->noMatchedRowOutsideStore(self::TABLE, self::COLUMN, [$code], $store)) {
            return $this->deny($request, __('This ticket could not be refunded.'));
        }

        // The code is inside the store. It must also be THIS customer's, which is the handler's
        // other key -- checked here so that a code belonging to a different shopper in the same
        // store is refused rather than deleted.
        $ownedByCaller = DB::table(self::TABLE)
            ->where(self::COLUMN, trim((string) $code))
            ->where('store_id', $store)
            ->where('customer_id', $customer->id)
            ->exists();

        if (! $ownedByCaller) {
            return $this->deny($request, __('This ticket could not be refunded.'));
        }

        return $next($request);
    }

    /**
     * The store this refund belongs to: the one the URL slug names.
     *
     * THE SLUG IS CALLER-SUPPLIED AND IT STILL AUTHORISES NOTHING. It only NAMES the store that
     * the two checks in handle() are then applied to -- the set predicate, which refuses if the
     * code reaches any row outside it, and the ownership read, which requires a row inside it
     * belonging to this customer. A caller naming a store can therefore only ever reach their own
     * tickets in that store, which is exactly the handler's intent.
     *
     * AN EARLIER VERSION ALSO REQUIRED THE SLUG'S STORE TO EQUAL THE CUSTOMER'S OWN `store_id`,
     * AND THAT WAS A REGRESSION I INTRODUCED. It refused a refund the module's own screen offers:
     * `refund_lottery():862` lists
     *
     *     PurchaseTicket::where('store_id', $slugStore)
     *         ->where('customer_id', auth('customers')->user()->id)
     *
     * so a shopper registered at store A who bought a ticket on store B's storefront sees that
     * ticket listed as a radio input on B's refund form (theme/refund-lottery-form.blade.php:166),
     * submits it to B's slug, and was then refused permanently -- with the deliberately uniform
     * refusal body, so neither they nor support could tell it from the attack refusal. That
     * mismatch is not an anomaly: it is the PREMISE of the defect this guard fixes, because
     * `customers` is one session guard and `StoreLotteryPayment:613` stamps a shopper's id onto
     * rows in whichever store they are browsing.
     *
     * Dropping that limb does not weaken the guard. The attack -- posting store A's slug with
     * store B's code -- is refused by the set predicate, which matches a row whose `store_id` is B
     * and not A. Verified: every negative test in LotteryTicketWriterTest still fails on reversion
     * after the change, including `test_a_slug_naming_a_different_store_is_refused`.
     */
    private function resolveStore(Request $request): ?int
    {
        $slug = $request->route('storeSlug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return $this->usableId(DB::table('stores')->where('slug', $slug)->value('id'));
    }

    /**
     * ONE REFUSAL BODY FOR EVERY REASON ABOVE, and that is deliberate.
     *
     * A refusal that said "no such ticket" for one case and "not yours" for another would answer
     * the attacker's actual question -- whether a code exists in some other store. #64's guard
     * shipped with a disclosing body and the removal check could not see it, because the test that
     * looked compared two denials that happened to share a reason; the uniform body is asserted
     * directly now, in both files.
     *
     * The sign-in and no-code cases carry their own text because neither reveals anything about
     * another tenant's rows and both are ordinary user mistakes.
     */
    private function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => $message], 403);
        }

        return redirect()->back()->with('error', $message);
    }
}
