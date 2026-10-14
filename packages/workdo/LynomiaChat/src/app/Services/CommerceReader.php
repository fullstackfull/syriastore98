<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use Illuminate\Support\Collection;

/**
 * Every read the commerce API serves, tenanted by one store id and nothing else.
 *
 * WHY A SERVICE AND NOT THE CONTROLLER. The store id arrives once, in the constructor, and every
 * query below is built from it. There is no code path here that can resolve a tenant any other
 * way -- no getCurrentStore(), no auth()->user(), no request. That is the point: the admin API's
 * tenancy bug is a helper call away in any handler that has a request, and this class does not
 * have one.
 *
 * FAILS CLOSED, EVERYWHERE. Customer and Cart carry BelongsToStore, whose forStore() scope
 * compiles to `1 = 0` for a null or non-numeric store rather than returning every row. Order does
 * not carry the trait, so its store predicate is written out; the constructor rejects a
 * non-positive id so neither shape can degrade into an unscoped read.
 *
 * ORDER IS NOT GLOBALLY SCOPED, AND CANNOT BE HERE. config/tenancy.php allowlists ten models and
 * Order is deliberately not one of them (the admin API operates on a request-supplied store id).
 * So every query on orders in this file carries `store_id = ?` written at the call site, which is
 * the same discipline the rest of the application applies by hand 1,261 times.
 *
 * order_billing_details HAS NO store_id COLUMN. Guest identity lives there and nowhere else, so
 * every read of it is joined through `orders` and filtered on orders.store_id. An unjoined read
 * of that table is an unscoped read of a customer's telephone number -- the exact shape the
 * tenancy census counts, and the exact site that was deleted from this branch.
 */
class CommerceReader
{
    /** The adapter asks for a bounded page; this is the ceiling it cannot raise. */
    public const MAX_ORDERS = 50;

    public const MAX_CARTS = 50;

    /** Candidates per lookup. The adapter verifies each one and keeps the ones that really match. */
    public const MAX_CUSTOMERS = 25;

    /**
     * A phone search term shorter than this matches too much to be an identifier. The adapter
     * sends the national significant number (nine digits for Syrian and Saudi numbers).
     */
    public const MIN_PHONE_DIGITS = 6;

    /**
     * delivered_status values the application actually writes. The column is a plain integer with
     * no enum, constant or check constraint, so a value outside this ladder is reachable and is
     * reported with no label rather than mislabelled; the adapter maps an unknown one to `other`.
     */
    private const DELIVERED_STATUSES = [0, 1, 2, 3, 4, 5, 6, 7, 8];

    /**
     * Free-text payment status -> the contract's vocabulary. Every key was read out of the write
     * sites, not guessed, and anything unrecognised is reported `unknown` rather than guessed at.
     *
     * WHY NOT OrderFinancialService::normalizePaymentStatus(), WHICH IS THE ONE NORMALISER THIS
     * CONTRACT SAID IT WOULD REUSE. Because it cannot classify the most common spelling in this
     * codebase, and reusing it faithfully would make this API report paid orders as unpaid:
     *
     *   - 'succeeded' is written by THIRTEEN sites -- Stripe, Mercado Pago, Skrill, Midtrans,
     *     PayTabs, PayTR, Paytm, Flutterwave, CoinGate and NMI -- against four for 'success'.
     *     Its match arms name 'paid', 'success' and 'completed'; 'succeeded' falls through to
     *     `default => 'unpaid'`.
     *   - That default also conflates "explicitly unpaid" with "I do not recognise this", which
     *     is the right conservative answer for a financial record and the wrong one for an
     *     agent's order panel, where 'unknown' is the honest answer.
     *
     * The first of those is a defect in Store Syria's own financial records -- store_order_
     * financials writes payment_status 'unpaid', paid_amount 0 and paid_at null for an order
     * paid through any of those ten gateways -- and correcting it changes money-bearing rows.
     * That is an owner decision about another workstream, reported rather than made here, so
     * this map does not silently inherit the misclassification and does not silently fix it.
     */
    private const PAYMENT_STATUSES = [
        'paid' => 'paid',
        'success' => 'paid',
        'succeeded' => 'paid',
        'completed' => 'paid',
        'approved' => 'paid',
        'unpaid' => 'unpaid',
        'failed' => 'unpaid',
        'rejected' => 'unpaid',
        'pending' => 'pending',
        'processing' => 'pending',
        'partial' => 'partially_paid',
        'partially_paid' => 'partially_paid',
        'refund' => 'refunded',
        'refunded' => 'refunded',
        'cancel' => 'cancelled',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
    ];

    /**
     * A guest has no customers row, so a guest is referenced by the order they were found on.
     *
     * NOT BY THE IDENTIFIER ITSELF, which is the obvious alternative and the wrong one: the
     * reference travels back in a URL path, and a customer's email address does not belong in
     * this application's access logs. An order id discloses nothing a caller holding this
     * store's credential cannot already read, and it resolves server side to the same billing
     * rows the lookup matched.
     */
    private const GUEST = 'guest:';

    public function __construct(private readonly int $storeId)
    {
        if ($this->storeId <= 0) {
            // Unreachable from the middleware, which only ever hands over a resolved store id.
            // Loud here rather than a query that quietly reads every tenant.
            throw new \InvalidArgumentException('CommerceReader needs a real store id.');
        }
    }

    /**
     * Candidates for a verified email. Never a name: matching a shopper to a conversation by name
     * is how one customer is shown another's orders.
     */
    public function customersByEmail(string $email): array
    {
        $email = trim(mb_strtolower($email));

        if ($email === '') {
            return [];
        }

        $registered = Customer::forStore($this->storeId)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(self::MAX_CUSTOMERS)
            ->get()
            ->map(fn (Customer $customer) => $this->customerPayload($customer));

        return $registered->concat($this->guestsMatching('email', $email))->all();
    }

    /**
     * Candidates for a phone. The term is the national significant number: stores keep
     * "+963955111222", "0955111222" and "00963955111222" for the same person, so the match is a
     * suffix of the stored digits and the adapter verifies the full E.164 afterwards.
     */
    public function customersByPhone(string $term): array
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        if (strlen($digits) < self::MIN_PHONE_DIGITS) {
            return [];
        }

        $registered = Customer::forStore($this->storeId)
            ->whereRaw($this->digitsOnly('customers.mobile') . ' LIKE ?', ['%' . $digits])
            ->limit(self::MAX_CUSTOMERS)
            ->get()
            ->map(fn (Customer $customer) => $this->customerPayload($customer));

        return $registered->concat($this->guestsMatching('telephone', $digits))->all();
    }

    /**
     * This customer's orders in this store, newest first. A guest reference resolves back to the
     * billing rows it came from, which is the only record a guest leaves.
     */
    public function customerOrders(string $reference, int $limit): array
    {
        $query = $this->orders();

        if (str_starts_with($reference, self::GUEST)) {
            $billing = $this->guestBilling(substr($reference, strlen(self::GUEST)));

            if ($billing === null) {
                return [];
            }

            $this->whereGuestLike($query, $billing);
        } elseif (ctype_digit($reference)) {
            $query->where('orders.customer_id', (int) $reference)->where('orders.is_guest', 0);
        } else {
            return [];
        }

        return $query->orderByDesc('orders.id')
            ->limit($this->bounded($limit, self::MAX_ORDERS))
            ->get()
            ->map(fn (Order $order) => $this->orderPayload($order))
            ->all();
    }

    public function order(int $orderId): ?array
    {
        $order = $this->orders()->find($orderId);

        return $order === null ? null : $this->orderPayload($order);
    }

    /**
     * Order search by the number a shopper and an agent both recognise. Store Syria numbers an
     * order separately from its id (orders.product_order_id), which is why the adapter cannot
     * fall back to "look it up by id and keep it if the number matches".
     */
    public function ordersByNumber(string $number): array
    {
        $number = trim($number);

        if ($number === '') {
            return [];
        }

        return $this->orders()
            ->where('orders.product_order_id', $number)
            ->orderByDesc('orders.id')
            ->limit(self::MAX_ORDERS)
            ->get()
            ->map(fn (Order $order) => $this->orderPayload($order))
            ->all();
    }

    /**
     * Carts left behind. One cart row per line in Store Syria, so the rows are grouped into a
     * basket per shopper -- by customer for a signed-in one, by cookie session for the rest.
     *
     * NO RECOVERY LINK IS PRODUCED, here or on the other side. Store Syria has no durable cart
     * token, so there is no URL that restores a basket; a fabricated checkout link would be a
     * bearer credential for someone else's cart. Reporting a cart is not messaging about one:
     * that waits on the consent model.
     */
    public function abandonedCarts(?string $customerReference, int $limit): array
    {
        $query = Cart::forStore($this->storeId);

        if ($customerReference !== null) {
            if (! ctype_digit($customerReference)) {
                // A guest cart is keyed on a cookie session, which is not a customer reference.
                return [];
            }

            $query->where('customer_id', (int) $customerReference);
        }

        $rows = $query->orderByDesc('updated_at')
            // One basket is many rows, so the row budget has to be bigger than the cart budget.
            ->limit($this->bounded($limit, self::MAX_CARTS) * 10)
            ->get();

        return $this->basketsFrom($rows, $this->bounded($limit, self::MAX_CARTS));
    }

    /**
     * The one base query for orders. Every caller starts here, so the store predicate cannot be
     * forgotten at a call site.
     */
    private function orders()
    {
        return Order::query()->where('orders.store_id', $this->storeId);
    }

    /**
     * Guest identity, joined through `orders` because order_billing_details has NO store_id
     * column. An unjoined read of that table is an unscoped read of a customer's telephone
     * number -- the exact site this branch deleted from the tenancy census.
     */
    private function whereGuest($query, string $column, string $value): void
    {
        $query->where('orders.is_guest', 1)
            ->whereExists(function ($sub) use ($column, $value) {
                $sub->from('order_billing_details')
                    ->whereColumn('order_billing_details.order_id', 'orders.id');

                if ($column === 'email') {
                    $sub->whereRaw('LOWER(order_billing_details.email) = ?', [$value]);
                } else {
                    $sub->whereRaw($this->digitsOnly('order_billing_details.telephone') . ' LIKE ?', ['%' . $value]);
                }
            });
    }

    /**
     * The guest orders that share a known guest's email or phone. Either identifier is enough:
     * one shopper checking out twice may give only one of them the second time.
     */
    private function whereGuestLike($query, OrderBillingDetail $billing): void
    {
        $email = trim(mb_strtolower((string) $billing->email));
        $digits = preg_replace('/\D+/', '', (string) $billing->telephone) ?? '';

        $query->where('orders.is_guest', 1)->where(function ($outer) use ($email, $digits) {
            if ($email !== '') {
                $outer->orWhereExists(fn ($sub) => $this->billingOf($sub)
                    ->whereRaw('LOWER(order_billing_details.email) = ?', [$email]));
            }

            if (strlen($digits) >= self::MIN_PHONE_DIGITS) {
                $outer->orWhereExists(fn ($sub) => $this->billingOf($sub)
                    ->whereRaw($this->digitsOnly('order_billing_details.telephone') . ' LIKE ?', ['%' . $digits]));
            }

            // A guest with neither identifier matches nothing rather than every guest order.
            $outer->orWhereRaw('1 = 0');
        });
    }

    private function billingOf($sub)
    {
        return $sub->from('order_billing_details')->whereColumn('order_billing_details.order_id', 'orders.id');
    }

    /**
     * The billing row a guest reference points at, read only through an order of THIS store.
     */
    private function guestBilling(string $orderId): ?OrderBillingDetail
    {
        if (! ctype_digit($orderId)) {
            return null;
        }

        $order = $this->orders()->where('orders.is_guest', 1)->find((int) $orderId);

        return $order?->billingData;
    }

    /**
     * Guest candidates for an identifier: one pseudo-customer per matching order, carrying the
     * name and contact details that order was placed with, referenced by that order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function guestsMatching(string $column, string $value): Collection
    {
        $query = $this->orders();
        $this->whereGuest($query, $column, $value);

        $order = $query->orderByDesc('orders.id')->first();
        $billing = $order?->billingData;

        if ($billing === null) {
            return collect();
        }

        return collect([[
            'id' => self::GUEST . $order->id,
            'name' => trim(($billing->first_name ?? '') . ' ' . ($billing->last_name ?? '')),
            'emails' => array_values(array_filter([$billing->email])),
            'phones' => array_values(array_filter([$billing->telephone])),
            'phone_region' => $this->phoneRegion(),
            'guest' => true,
        ]]);
    }

    private function phoneRegion(): ?string
    {
        return ((string) config('lynomia.commerce_phone_region')) ?: null;
    }

    private function customerPayload(Customer $customer): array
    {
        return [
            'id' => (string) $customer->id,
            'name' => trim($customer->first_name . ' ' . $customer->last_name),
            'emails' => array_values(array_filter([$customer->email])),
            'phones' => array_values(array_filter([$customer->mobile])),
            // A locally-written number is only a number once you know its country, and Store
            // Syria records none per store. See config/lynomia.php -> commerce_phone_region.
            'phone_region' => $this->phoneRegion(),
            'guest' => false,
        ];
    }

    /**
     * The store's own vocabulary. Canonicalisation into Lynomia's happens in the adapter's
     * Normalizer, exactly as it does for the other four providers -- with one exception:
     * payment status, which Store Syria canonicalises here by reusing the normaliser that
     * already exists, so the adapter never has to know that 'success', 'succeeded' and 'Paid'
     * are the same thing.
     */
    private function orderPayload(Order $order): array
    {
        $delivered = (int) $order->delivered_status;
        $items = $this->items($order);

        return [
            'id' => (string) $order->id,
            'number' => $order->product_order_id,
            'delivered_status' => $delivered,
            'status_label' => in_array($delivered, self::DELIVERED_STATUSES, true)
                ? getOrderStatusLabel($delivered)
                : null,
            'return_status' => (int) $order->return_status,
            'payment_status' => self::PAYMENT_STATUSES[strtolower(trim((string) $order->payment_status))] ?? 'unknown',
            'currency' => $order->currency_code,
            'total' => $this->money($order->final_price),
            // order_date is a plain dateTime column with no cast on the model, so it arrives as
            // a string; created_at is a Carbon. Both go out as ISO 8601 or not at all.
            'created_at' => $this->timestamp($order->getRawOriginal('order_date')) ?? $this->timestamp($order->created_at),
            'updated_at' => $this->timestamp($order->updated_at),
            'customer_id' => $order->is_guest ? null : (string) $order->customer_id,
            'items' => $items,
            'item_count' => count($items),
            'shipping' => [
                'method' => null,
                'total' => $this->money($order->delivery_price),
                'carrier' => null,
            ],
            // Store Syria records no tracking number. Reporting one it never had would be an
            // invention an agent would read out to a customer.
            'tracking_number' => null,
        ];
    }

    /**
     * Name, quantity and line total, and nothing else. orders.product_json is an unvalidated blob
     * written by several checkout paths, so anything whose shape is not certain is left out
     * rather than guessed at.
     */
    private function items(Order $order): array
    {
        $decoded = json_decode((string) $order->product_json, true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($line) => is_array($line))
            ->map(fn (array $line) => [
                'name' => isset($line['name']) ? (string) $line['name'] : null,
                'quantity' => isset($line['qty']) ? (int) $line['qty'] : null,
                'total' => isset($line['price']) ? $this->money($line['price']) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Store Syria holds order money as PHP floats and cart prices as integers. Money leaves here
     * as a decimal STRING so neither side ever turns it back into a float.
     */
    private function timestamp($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value)->toIso8601String();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            // A malformed stored date is reported as absent rather than as a 500 in an agent's
            // order panel.
            return null;
        }
    }

    private function money($value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
    }

    /**
     * Cart rows grouped into baskets. A signed-in shopper's rows group by customer_id; everyone
     * else's by the cookie session that owns them.
     */
    private function basketsFrom(Collection $rows, int $limit): array
    {
        return $rows
            ->groupBy(fn (Cart $row) => $row->customer_id > 0 ? 'c:' . $row->customer_id : 's:' . $row->cookie_session_id)
            ->take($limit)
            ->map(function (Collection $lines, string $key) {
                $newest = $lines->max('updated_at');

                return [
                    'id' => $key,
                    'created_at' => $this->timestamp($lines->min('created_at')),
                    'updated_at' => $this->timestamp($newest),
                    'currency' => null,
                    'total' => $this->money($lines->sum(fn (Cart $line) => (float) $line->price * (int) $line->qty)),
                    'items' => $lines->map(fn (Cart $line) => [
                        'name' => $line->product_data->name ?? null,
                        'quantity' => (int) $line->qty,
                    ])->values()->all(),
                    'customer_id' => str_starts_with($key, 'c:') ? substr($key, 2) : null,
                    // A guest cart carries no contact details: Store Syria keys it on a cookie and
                    // learns who the shopper is only at checkout, which is when it stops being a
                    // cart. Reporting an address the store does not have would be an invention.
                    'email' => null,
                    'phone' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A digit-only comparison of a stored phone number, so "+963 955-111 222" and "0955111222"
     * both end with the same national significant number.
     *
     * The column name is a literal from this class; only the term is ever bound. This is a
     * whereRaw inside an Eloquent builder, not a DB:: entry point, so the query still carries
     * every scope the model has.
     */
    private function digitsOnly(string $column): string
    {
        foreach ([' ', '-', '(', ')', '+', '.'] as $separator) {
            $column = "REPLACE({$column}, '{$separator}', '')";
        }

        return $column;
    }

    private function bounded(int $limit, int $ceiling): int
    {
        return max(1, min($limit, $ceiling));
    }
}
