<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\UnsubscribeTokens;
use Illuminate\Http\Request;

/**
 * THE PAGE AN UNSUBSCRIBE LINK OPENS. D8-3a / GAP-200 / MKT-008.
 *
 * `UnsubscribeTokens` has existed since D8-1 and was unwired: a token could be issued and redeemed in
 * PHP and nothing a recipient could click reached it. This is the seam that makes the link real, and it
 * obeys the three rules that service's own docblock sets for whatever would call it:
 *
 *   1. **A GET NEVER REDEEMS.** `show()` only reads the token and renders a confirmation; `redeem()`,
 *      on POST, is the only thing that withdraws. A token is reusable BY DESIGN -- an unsubscribe link
 *      that stops working is a failure to honour an unsubscribe -- so a mail scanner or link prefetcher
 *      following the GET of an OLD link would otherwise withdraw an opt-in the person has since given
 *      again. The POST carries the session CSRF token from the form on the page.
 *   2. **BOTH ROUTES ARE THROTTLED** (see routes/customer.php). Every recipient holds a working link and
 *      every redemption appends a row to a table nothing may prune.
 *   3. **THE STORE IS THE ONE THE PAGE IS SERVED UNDER.** `getStore($storeSlug)` resolves it from the
 *      ROUTE, and it is passed to the service as the required `$expectedStoreId`. CLAUDE.md records why
 *      this cannot be `getCurrentStore()`: that reads the `web` guard and answers for a merchant's panel
 *      session, not for the store whose shopper is holding this link. An unknown slug resolves to null
 *      and the page fails closed.
 *
 * WHAT IT NEVER DOES. It does not read the plain address: the token row holds a hash and a masked hint
 * (`s***@example.test`), and the hint is the only thing shown. It cannot grant -- the token is a
 * capability to withdraw one contact on one channel at one store, and nothing here can widen it. And a
 * token that belongs to ANOTHER store is answered exactly like one that was never issued, through the
 * same `unknown()` response, so the holder learns nothing about where it is valid.
 *
 * IT IS NOT GATED BY `MARKETING_CONSENT_ENFORCEMENT`. Honouring an unsubscribe is not an enforcement
 * decision: the withdrawal is recorded whatever the send gate's mode is, so that turning enforcement on
 * later finds the record already there.
 */
final class UnsubscribeController extends Controller
{
    public function __construct(private readonly UnsubscribeTokens $tokens) {}

    /** The confirmation page. READ ONLY -- see rule 1. */
    public function show(string $storeSlug, string $token)
    {
        $store = getStore($storeSlug);

        if (empty($store)) {
            return $this->unknown();
        }

        $row = $this->tokens->find($token, (int) $store->id);

        if ($row === null) {
            return $this->unknown();
        }

        return view('marketing.unsubscribe', [
            'state' => 'confirm',
            'storeName' => (string) $store->name,
            'hint' => (string) $row->address_hint,
            'action' => route('marketing.unsubscribe.redeem', [
                'storeSlug' => $storeSlug,
                'token' => $token,
            ]),
        ]);
    }

    /** The withdrawal. This is the only path that writes. */
    public function redeem(Request $request, string $storeSlug, string $token)
    {
        $store = getStore($storeSlug);

        if (empty($store)) {
            return $this->unknown();
        }

        // Read the row FIRST, so an unknown or wrong-store token takes the identical `unknown()` path
        // before anything is written, and so the hint shown afterwards comes from the row rather than
        // from anything the request supplied.
        $row = $this->tokens->find($token, (int) $store->id);

        if ($row === null) {
            return $this->unknown();
        }

        /*
         * Evidence is what this request can honestly say about itself. The ledger makes it encodable, so a
         * hostile header cannot stop the withdrawal being recorded; the user agent is bounded because the
         * header is attacker-controlled and the column is not a log.
         */
        $this->tokens->redeem($token, (int) $store->id, 'unsubscribe_page', [
            'via' => 'page',
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return view('marketing.unsubscribe', [
            'state' => 'done',
            'storeName' => (string) $store->name,
            'hint' => (string) $row->address_hint,
            'action' => null,
        ]);
    }

    /**
     * One answer for every link this store cannot honour: never issued, issued for another store, or
     * malformed. Identical in status and body, because a different answer for a wrong-store token tells
     * its holder that it is valid somewhere else.
     */
    private function unknown()
    {
        return response()->view('marketing.unsubscribe', [
            'state' => 'unknown',
            'storeName' => null,
            'hint' => '',
            'action' => null,
        ], 404);
    }
}
