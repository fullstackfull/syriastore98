<?php

namespace App\Services;

/**
 * WHICH ORDERS THIS SESSION IS ENTITLED TO SEE ON THE ANONYMOUS THANK-YOU PAGE.  P1-ORD-13.
 * =================================================================================================
 *
 * THE PROBLEM THE NUMBER CANNOT SOLVE. `orders.product_order_id` is a plain nullable string with no
 * index, and a guest order is `'0' . date('YmdHis')` -- so two orders placed in one store in one
 * second carry the SAME number. Every gateway return handler redirects to the thank-you page
 * flashing that number (`->with('data', $order->product_order_id)`, ten sites), and
 * `OrderController::orderComplete()` resolved it by looking the number up.
 *
 *   * BEFORE P1-ORD-08 the lookup was `first()` -- the LOWEST id -- so a visitor who placed their
 *     own order a second after a victim's was shown the VICTIM'S order and a minted link to it
 *     (executed, review lens E, P8).
 *   * P1-ORD-08 made an ambiguous number refuse instead of guessing. Safe, and a FALSE DENIAL for
 *     both buyers in a collision: each of them is sent back to the landing page having just paid.
 *
 * Neither is acceptable, and no amount of care with the NUMBER fixes it, because the number is not
 * an identifier. The order's PRIMARY KEY is, and this is how the buyer's own session comes to hold
 * it.
 *
 * WHY A SESSION LIST AND NOT A SIGNED TOKEN. `APP_KEY` is in this repository's git history and
 * unrotated (CLAUDE.md), so `Crypt::encrypt($id)` is an encoding rather than a control: a forged
 * token would be accepted, and forging one needs only the leaked key. The session's PAYLOAD is
 * server-held and is not derivable from the key, so an attacker cannot mint a session that already
 * holds an id. Stated precisely, because the first draft of this comment overclaimed: the session
 * ID itself travels in a cookie encrypted with that same leaked key, so an attacker CAN mint a
 * valid cookie naming an id of their choosing -- it just yields an EMPTY session. Using it needs
 * the victim's browser to adopt that id (XSS, a sibling subdomain, plaintext HTTP), which is the
 * generic session-fixation precondition and not something this mechanism introduces. The control
 * rests on the payload, not on the id.
 *
 * This is the mechanism P1-ORD-08 already introduced for the WhatsApp/Telegram redirect
 * (PaymentController::whatsapp); P1-ORD-13 lifts it out of that one handler so every gateway return
 * can use it, and so there is one definition of "this session placed that order" rather than ten
 * copies.
 *
 * WHAT THE LIST IS NOT. It is not proof of identity beyond "whoever holds this session cookie". Two
 * consequences, both recorded on the task rather than fixed here: with nothing flashed, the
 * thank-you page becomes a re-readable view of the session's most recent order in that store for the
 * session's life (120 min idle), so on a shared browser the next person reads the previous buyer's
 * order number and minted link; and `remember()` must never be reached by a caller who is not the
 * buyer -- see PaymentController::orderWrittenBy(), which is what keeps the anonymous Paymera
 * trigger from acquiring one.
 *
 * THE CAP IS DELIBERATE. A long-lived session must not grow an unbounded list, and a buyer only
 * ever needs the orders they placed recently -- the thank-you page is read once, immediately after
 * paying.
 */
final class OrderCompleteBinding
{
    public const SESSION_KEY = 'order_complete_ids';

    /** The most recent ids kept per session. A buyer reads the thank-you page once. */
    private const KEEP = 20;

    /**
     * Record that THIS session placed this order, so its thank-you page may resolve it by id.
     *
     * @param  mixed  $orderId
     */
    public static function remember($orderId): void
    {
        $id = self::asId($orderId);

        if ($id === null) {
            return;
        }

        $ids = self::bound();
        $ids[] = $id;

        session()->put(self::SESSION_KEY, array_slice(array_values(array_unique($ids)), -self::KEEP));
    }

    /**
     * The ids this session placed.
     *
     * Every value is re-validated on the way out as well as in: the session store is not a typed
     * column, and a value that arrived through some other writer (or an older format) must not be
     * able to turn into a bare `Order::find()` argument.
     *
     * @return list<int>
     */
    public static function bound(): array
    {
        $raw = session(self::SESSION_KEY, []);

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = self::asId($value);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Did this session place this order? */
    public static function holds($orderId): bool
    {
        $id = self::asId($orderId);

        return $id !== null && in_array($id, self::bound(), true);
    }

    /**
     * A positive integer, or null.
     *
     * FILTER_VALIDATE_INT rather than a cast or `is_numeric`, which is this project's recorded
     * rule: MySQL coerces '5abc' to 5 in a `WHERE id = ?` comparison, so a value the parse accepts
     * and the engine reads differently is a guard that does not hold.
     *
     * @param  mixed  $value
     */
    private static function asId($value): ?int
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $id = filter_var(trim((string) $value), FILTER_VALIDATE_INT);

        return ($id === false || $id <= 0) ? null : $id;
    }
}
