<?php

namespace App\Support\Commerce;

use Illuminate\Support\Facades\Cookie;

/**
 * P4-F004 -- WHICH BASKET A GUEST IS ADDING TO, DECIDED IN ONE PLACE.
 *
 * A guest's basket rows are told apart by `carts.cookie_session_id` alone: `customer_id` is 0 for
 * every one of them. Which value that is has always been decided twice, and the two disagreed.
 *
 *   READERS  -- `CartController::cart_remove`, `cartClear`, `change_cart`,
 *               `Cart::cart_list_cookie`, `PaymentService`, the gateway return handlers and
 *               `CustomerLoginController`'s sign-in migration -- all key on the `cart` COOKIE.
 *   THE WRITER -- `CartController::product_cartlist` -- keyed on `session()->getId()`, stamped the
 *               row with it, and then OVERWROTE the cookie with it.
 *
 * `config/session.php` gives a session 120 minutes; the cart cookie is queued for 1440. So the
 * cookie outlives the session that minted its value for twenty-two hours of every day, and the
 * first add after the session id turns over filed the new line under an identifier no earlier row
 * carried -- and renamed the basket to it. Every earlier line became unreadable, unbuyable and
 * undeletable in the same instant. To the shopper, adding an item emptied the basket.
 *
 * SO THE COOKIE DECIDES, AND THE SESSION IS ONLY THE FALLBACK. Reusing the identifier the request
 * already carries is what the four readers have always done; the writer was the one that differed.
 * The session is still used when there is no usable cookie, which is both the first-time visitor
 * and the shopper whose cookie was cleared while their session survived -- the one case where
 * today's behaviour recovers a basket, and it is kept.
 *
 * THIS DOES NOT WIDEN WHAT A COOKIE CAN DO, and the reason is narrower than it first looks.
 * `bootstrap/app.php` declares no `encryptCookies(except:)`, so Laravel 11's `EncryptCookies`
 * covers `cart` and the plaintext is name-bound: a party can REPLAY a cookie the server issued,
 * not MINT one naming a value they chose. The identifier space is therefore unchanged by this
 * class -- server-minted session ids, exactly as before. What a replayed cookie already reaches,
 * on routes with no auth middleware, is the whole basket: `CartController::cartClear` (:171) runs
 * `Cart::forGuestCookie($cartId)->delete()` with no store predicate at all, `cart_remove` (:136)
 * deletes a line, `Cart::cart_qty_cookie` (:809) sets any line's quantity, and
 * `PaymentController` (:2134) charges for it. Reading the same cookie on the way IN adds one
 * thing to that set -- "and append a line that was not there" -- which is a real addition and a
 * marginal one beside "and empty all of it".
 *
 * THE ONE EXPOSURE THIS DOES CHANGE, stated rather than left to be found. Before, the writer
 * renamed the basket on every add, so a captured cookie stopped naming the live basket after the
 * victim's next add. That accidental invalidation is exactly the defect above -- it worked by
 * destroying the victim's own basket -- so persisting the identifier is the correct trade, but it
 * does mean a stolen basket cookie stays useful for the cookie's 24 hours. Making the identifier
 * unguessable, and no longer borrowed from the session id, is the real answer and a SEPARATE
 * change, because it has to carry live rows across; this class is where it would go.
 */
final class GuestBasketIdentity
{
    /** The cookie every reader of a guest basket already keys on. */
    public const COOKIE = 'cart';

    /**
     * The shape an identifier may have.
     *
     * NOT A SECURITY CONTROL -- the value is a bearer token either way, and `forGuestCookie()`
     * binds it as a parameter so nothing here is about injection. It is a COLUMN guard: the value
     * is written into `carts.cookie_session_id`, a `string` column, and without a bound any caller
     * decides what goes in it, including something far longer than it holds (which MySQL in
     * production's non-strict mode would silently truncate -- and a truncated identifier names a
     * basket that is not the one asked for).
     *
     * 20-64 is a window around the 40 alphanumeric characters `session()->getId()` produces, which
     * is the shape of every value in the column today. `GuestBasketIdentityTest` asserts that the
     * shipped session id still fits it, so a framework change to the id shape fails a test instead
     * of quietly restoring the orphaning this class exists to stop.
     *
     * `\z` RATHER THAN `$`, because PHP's `$` matches before a FINAL NEWLINE unless the `D`
     * modifier is set -- so `'/...{20,64}$/'` accepts a 20-character value with `\n` on the end
     * and this method would then store it. Nothing shipped emits one and the value is bound as a
     * parameter, so this is hardening rather than a live hole; it is changed because a bound that
     * does not mean what it says is the kind of thing a later reader relies on. Raised by the
     * independent review of this change.
     */
    private const SHAPE = '/^[A-Za-z0-9_-]{20,64}\z/';

    /**
     * The basket this request is about: its own cookie when that is usable, else the session.
     *
     * Reads the cookie through the SAME facade the readers use, so the writer cannot resolve a
     * different value from the same request than the reader that follows it.
     */
    public static function forRequest(): string
    {
        $cookie = Cookie::get(self::COOKIE);

        return self::isUsable($cookie) ? (string) $cookie : session()->getId();
    }

    /** Whether a value may be used -- and therefore stored -- as a basket identifier. */
    public static function isUsable($value): bool
    {
        return is_string($value) && preg_match(self::SHAPE, $value) === 1;
    }
}
