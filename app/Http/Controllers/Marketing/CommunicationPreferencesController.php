<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Marketing\ConsentLedger;
use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ContactAddress;
use Illuminate\Http\Request;

/**
 * THE OPT-IN. D8-3b / GAP-200 / MKT-008.
 *
 * THIS IS THE FIRST AND ONLY THING IN THE PRODUCT ALLOWED TO RECORD A GRANT, and it is listed in
 * `ConsentLedgerCensusTest::GRANT_CALLERS` deliberately for that reason. That list was EMPTY until now:
 * the owner's direction is that consent is never inferred from an order, a registration or an address
 * book, so no code could write `granted` at all. The exception is a person ticking a box about
 * themselves while signed in as themselves, which is what this page is.
 *
 * WHY IT IS AUTHENTICATED. The unsubscribe token cannot be reused here: `UnsubscribeTokens` states that
 * a token can do exactly one thing -- withdraw the contact it was issued for -- and "cannot grant,
 * cannot name another contact". A grant therefore needs the person to prove who they are, so these
 * routes sit behind the storefront's customer-session gate.
 *
 * THE REQUEST BODY DECIDES NOTHING EXCEPT THE TICKS. The store comes from `$customer->store_id` and each
 * address from `$customer->email` / `$customer->mobile` -- the signed-in shopper's OWN row. Nothing here
 * reads a store, a customer id or an address from the request: CLAUDE.md records that a tenant predicate
 * built from caller-supplied input is not a tenant check and has produced four separate defects in this
 * codebase. The only thing taken from the body is whether a checkbox the page itself offered is on, read
 * through `boolean()` by the channel's own key. A shopper who opens ANOTHER store's slug therefore still
 * edits their own store's consent, because the slug is not consulted.
 *
 * IT WRITES ONLY ON A CHANGE. An unchanged choice appends nothing, so saving the page twice does not grow
 * a ledger that nothing may prune.
 *
 * A SUPPRESSION IS NOT TOUCHED. A complaint, a legal request or a bounce is a hard block; the state
 * machine keeps it, and a lift is a separate, deliberate act that does not grant. Rather than write an
 * event the projection would discard, the channel is skipped and the page says the address is blocked.
 */
final class CommunicationPreferencesController extends Controller
{
    /** Where a decision made on this page is recorded as having come from. */
    private const SOURCE = 'preference_centre';

    public function __construct(private readonly ConsentLedger $ledger) {}

    public function show(Request $request)
    {
        $customer = $this->signedInShopper();

        return view('marketing.communication-preferences', [
            'rows' => $this->rowsFor($customer),
            'saved' => false,
        ]);
    }

    public function update(Request $request)
    {
        $customer = $this->signedInShopper();
        $storeId = (int) $customer->store_id;

        foreach ($this->channelsFor($customer) as $key => [$channel, $address]) {
            $current = $this->ledger->stateOf($storeId, $channel, $address);

            // A hard block is never changed from here, in either direction.
            if ($current === ConsentState::Suppressed) {
                continue;
            }

            // The ONLY thing read from the request: whether the box this page offered is ticked.
            $wanted = $request->boolean($key);

            if ($wanted && $current !== ConsentState::Granted) {
                $this->ledger->grant($storeId, $channel, $address, self::SOURCE,
                    $this->evidence($request), (int) $customer->id, 'customer', (int) $customer->id);
            } elseif (! $wanted && $current === ConsentState::Granted) {
                $this->ledger->withdraw($storeId, $channel, $address, self::SOURCE,
                    $this->evidence($request), (int) $customer->id, 'customer', (int) $customer->id);
            }
        }

        return view('marketing.communication-preferences', [
            'rows' => $this->rowsFor($customer->fresh()),
            'saved' => true,
        ]);
    }

    /** @return array<string, mixed> what this request can honestly say about itself */
    private function evidence(Request $request): array
    {
        return [
            'via' => 'preference_centre',
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ];
    }

    /**
     * The channels this shopper actually has an address for, keyed by the form field name.
     *
     * The address is NORMALISED here, through the same helper the ledger and the send gate use, so the
     * key a grant is written under is the key a send will later look up. A row with no usable address
     * offers no checkbox at all rather than a box that could never work.
     *
     * @return array<string, array{0: Channel, 1: string}>
     */
    private function channelsFor(Customer $customer): array
    {
        /*
         * A SHOPPER WITH NO STORE IS OFFERED NOTHING. `customers.store_id` is nullable and
         * `config/tenancy.php` records that this deployment may still hold rows from before the column
         * existed, so `(int) null` is 0. `ConsentLedger::stateOf()` tolerates 0 and the page would have
         * rendered ordinary unticked boxes — but a tick then reached `grant(0, ...)`, which throws
         * InvalidArgumentException, so that shopper got an HTTP 500 on every save and nothing was ever
         * written. Offering no channel is the honest answer: there is no store whose list they could be
         * on. Found by the D8-3 tenant-authz review (F4).
         */
        if ((int) $customer->store_id <= 0) {
            return [];
        }

        $out = [];

        $email = ContactAddress::normalise(Channel::Email, (string) $customer->email);

        if ($email !== null) {
            $out['email'] = [Channel::Email, $email];
        }

        foreach ([Channel::Whatsapp, Channel::Sms] as $channel) {
            $phone = ContactAddress::normalise($channel, (string) $customer->mobile);

            if ($phone !== null) {
                $out[$channel->value] = [$channel, $phone];
            }
        }

        return $out;
    }

    /**
     * What the page renders: one row per offered channel, with the MASKED address and the current state.
     *
     * @return list<array{key: string, channel: string, hint: string, granted: bool, blocked: bool}>
     */
    private function rowsFor(Customer $customer): array
    {
        $storeId = (int) $customer->store_id;
        $rows = [];

        foreach ($this->channelsFor($customer) as $key => [$channel, $address]) {
            $state = $this->ledger->stateOf($storeId, $channel, $address);

            $rows[] = [
                'key' => $key,
                'channel' => $channel->value,
                'hint' => ContactAddress::mask($channel, $address),
                'granted' => $state === ConsentState::Granted,
                'blocked' => $state === ConsentState::Suppressed,
            ];
        }

        return $rows;
    }

    /**
     * The signed-in shopper, from the customers guard and nowhere else.
     *
     * The route's middleware already refuses a guest; this is the fail-closed restatement, because a
     * handler that writes consent must never run for a principal it cannot name. It also means the class
     * never consults `getCurrentStore()`, which reads the WEB guard and would answer for a merchant's
     * panel session rather than for this shopper.
     */
    private function signedInShopper(): Customer
    {
        $customer = auth('customers')->user();

        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }
}
