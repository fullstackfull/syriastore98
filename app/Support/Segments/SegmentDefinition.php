<?php

namespace App\Support\Segments;

use Illuminate\Database\Eloquent\Builder;

/**
 * MKT-004 — A DYNAMIC SEGMENT'S RULE, AND THE ONLY PLACE THAT DECIDES WHAT A RULE MAY BE.
 *
 * A dynamic segment is a SAVED PREDICATE, not a stored list: its membership is whatever matches when
 * it is read. That is the whole difference from a static segment, and it is why this class exists
 * separately from the model — the predicate has to be sanitised on READ, every time, against the
 * vocabulary the code currently knows.
 *
 * WHY SANITISE ON READ AND NOT ONLY ON WRITE. The row outlives the code that wrote it. A rule name
 * that was valid last year must not reach a query builder this year merely because it is in the
 * table, and a row can be edited by hand, restored from a dump, or written by a future version with
 * a wider vocabulary. `SavedView::appliedFilters()` already applies exactly this discipline to a
 * saved filter, for exactly this reason, and this follows it rather than inventing a second posture.
 *
 * NOTHING HERE IS INTERPOLATED INTO SQL. Every rule maps to a fixed Eloquent expression with bound
 * parameters; the stored JSON supplies only VALUES, and each value goes through its own coercion
 * (`nonNegativeInt`, `date`). There is no column name, no operator and no fragment taken from the
 * row. `APP_KEY` is in git history, so `Crypt::encrypt()` is an encoding rather than a control and a
 * `decrypt()` or `unserialize()` over stored bytes is a deserialization door — which is why the
 * column is plain JSON read through an allowlist instead.
 *
 * THE VOCABULARY IS COUNTS AND DATES, AND THAT IS A CORRECTNESS DECISION RATHER THAN A SMALL START.
 * A SPEND rule ("customers who spent more than X") is currency-sensitive: the metric layer refuses a
 * mixed-currency total rather than summing one, which is right for a figure, but a segment cannot
 * refuse — it would silently EXCLUDE every customer whose orders are in another currency and the
 * merchant would never see it. That needs its own decision about denomination. Counts and dates are
 * currency-free, so they are what is offered, and the omission is named in the migration and on the
 * requirement row rather than hidden.
 *
 * NO RULE SHIPS A THRESHOLD. Every value in a rule is one the merchant typed. "At least how many
 * orders" and "not since when" are marketing policy, and this project does not invent those — the
 * same rule ANA-009 is built on.
 */
final class SegmentDefinition
{
    /**
     * The rules a dynamic segment may be built from.
     *
     * Each entry declares what the merchant must supply, so the form, the validator and the
     * evaluator all read one list rather than three that can drift.
     *
     *   `value`  — 'int'  a whole number the merchant types (a count)
     *            — 'date' a calendar date the merchant picks
     *            — null   the rule takes no value
     *
     *   `needs`  — OPTIONAL. A `customers` column the rule cannot be evaluated without, because a
     *              VENDORED module declares it. Absent for a rule that depends on nothing.
     *              {@see self::availableRules()} and {@see self::capabilityMet()}.
     */
    public const RULES = [
        'min_orders' => ['value' => 'int', 'label' => 'Has placed at least this many orders'],
        'max_orders' => ['value' => 'int', 'label' => 'Has placed at most this many orders'],
        'ordered_since' => ['value' => 'date', 'label' => 'Has ordered on or after'],
        'not_ordered_since' => ['value' => 'date', 'label' => 'Has NOT ordered on or after'],
        'has_abandoned_cart' => ['value' => null, 'label' => 'Has a basket left behind'],
        /*
         * MKT-005 — THE VIP RULE. «نقاط ولاء وVIP وإحالات» names loyalty points and VIP in one
         * clause, and `create_customer_segments_tables`' own docblock uses "VIP customers" as its
         * worked example of a shared segment — which this vocabulary could not express until now.
         *
         * A SEGMENT RULE AND NOT A SECOND ENGINE: `ARCH-002` forbids one, and a VIP tier IS a saved
         * predicate over customers, which is what a dynamic segment is.
         *
         * CURRENCY-FREE, which is why this rule is offered where a SPEND rule still is not. The
         * paragraph above states the spend problem — a segment cannot refuse a mixed-currency total
         * the way the metric layer does, so it would silently exclude customers whose orders are in
         * another currency. Points carry no denomination, so none of that applies.
         *
         * `needs` IS LOAD-BEARING. `customers.total_club_point` is declared by
         * `packages/workdo/RewardClubPoint/src/database/migrations/2024_04_17_105551_…`, a VENDORED
         * module migration that only runs when the module is installed. Measured: the column is
         * ABSENT from this application's own schema, so naming it unconditionally would throw
         * `no such column` wherever `RewardClubPoint` is not installed.
         */
        'min_loyalty_points' => [
            'value' => 'int',
            'label' => 'Has at least this many loyalty points',
            'needs' => 'total_club_point',
        ],
    ];

    /**
     * The rules a form may OFFER right now — `RULES` minus the ones whose column is not there.
     *
     * TWO LISTS, DELIBERATELY, AND THEY ANSWER DIFFERENT QUESTIONS:
     *
     *   `RULES`           what a stored definition MAY contain, i.e. what sanitising reads against.
     *   `availableRules()` what a merchant may newly choose.
     *
     * Collapsing them would do real damage in both directions. Sanitising against the narrow list
     * would DELETE a merchant's saved threshold the first time the segment was opened in an
     * environment without the module — and installing the module later would not bring it back,
     * because the row would already have been rewritten. Offering the wide list would let a merchant
     * save a rule that silently matches nobody with nothing on screen to explain it.
     *
     * `Schema::hasColumn()` AND NOT A MODULE CHECK. `module_is_active()` resolves its principal from
     * `Auth::check()` first, so it answers false from a console command or a test body with nobody
     * signed in while the `add_on_managers` row reads true (`CLAUDE.md` §1) — and the thing this
     * rule actually needs is the COLUMN, which outlives the activation either way: a store that
     * installs the module, earns points and then deactivates it still has balances worth segmenting.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function availableRules(): array
    {
        return array_filter(
            self::RULES,
            static fn (array $spec): bool => self::capabilityMet($spec)
        );
    }

    /** @param array<string, mixed> $spec */
    private static function capabilityMet(array $spec): bool
    {
        if (! isset($spec['needs'])) {
            return true;
        }

        /*
         * `hasColumn()` HITS THE SCHEMA, so it is asked once per rule per evaluation rather than per
         * customer row — the callers are a form render and `applyTo()`, both of which run it a fixed
         * number of times. Not memoised on purpose: a static cache would survive `RefreshDatabase`
         * between tests in one process and make the capability answer for the previous test's
         * schema, which is this project's `Cachable` trap in another costume.
         */
        return \Illuminate\Support\Facades\Schema::hasColumn('customers', (string) $spec['needs']);
    }

    /** @var array<string, int|string> rule key => the merchant's value (absent for a valueless rule) */
    private array $rules;

    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * Read a stored definition, keeping only what the vocabulary still knows.
     *
     * A malformed, unknown or out-of-range rule is DROPPED rather than refused, because this is the
     * read path: a segment whose row has one stale rule must still list its other rules rather than
     * throwing on a screen the merchant opened to fix it. `isEmpty()` then lets the caller say so.
     */
    public static function fromStored(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self([]);
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return new self([]);
        }

        return self::fromArray($decoded);
    }

    /** @param array<mixed> $input */
    public static function fromArray(array $input): self
    {
        $rules = [];

        foreach (self::RULES as $key => $spec) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            if ($spec['value'] === null) {
                /*
                 * A VALUELESS RULE IS ON OR ABSENT, and only a true-ish marker turns it on. A form
                 * posts an unchecked box as absent, but a hand-written row could carry `false` or
                 * `"0"`, and treating those as "on" would silently widen the segment.
                 */
                if (filter_var($input[$key], FILTER_VALIDATE_BOOLEAN)) {
                    $rules[$key] = true;
                }

                continue;
            }

            if ($spec['value'] === 'int') {
                $count = self::nonNegativeInt($input[$key]);

                if ($count !== null) {
                    $rules[$key] = $count;
                }

                continue;
            }

            $date = self::date($input[$key]);

            if ($date !== null) {
                $rules[$key] = $date;
            }
        }

        return new self($rules);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /** @return array<string, int|string|bool> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** What goes in the column: the sanitised rules, so a round trip cannot widen a segment. */
    public function toStored(): ?string
    {
        return $this->isEmpty() ? null : json_encode($this->rules);
    }

    /**
     * Narrow a `Customer` query to this store's customers that the rules match.
     *
     * THE STORE IS A PARAMETER AND NOT `getCurrentStore()`. `Customer` IS in
     * `config/tenancy.php`'s `scoped_models`, so a global scope does normally filter it — but
     * `null_tenant => 'open'` means it adds NO predicate when no tenant resolves, which is the case
     * for a console command and for any principal whose `current_store` is unset. A predicate that
     * is present for some callers and absent for others is not a tenant check, so the id is passed
     * in and applied here regardless.
     *
     * RULES COMBINE WITH AND, and that is stated rather than assumed: "at least 2 orders" plus "not
     * since 1 June" means both, which is how a merchant reads a list of conditions. OR would need
     * its own vocabulary (groups) and is not offered, so nothing here has to guess.
     *
     * AN EMPTY DEFINITION MATCHES NOTHING, not everything. A dynamic segment with no rules is
     * unconfigured, and the dangerous reading — "no filter, so all customers" — would silently
     * address a store's entire customer base. `whereRaw('1 = 0')` is a literal with no input.
     */
    public function applyTo(Builder $query, int $storeId): Builder
    {
        $query->where('customers.store_id', $storeId);

        if ($this->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($this->rules as $key => $value) {
            match ($key) {
                'min_orders' => $this->applyOrderCount($query, $storeId, '>=', (int) $value),
                'max_orders' => $this->applyOrderCount($query, $storeId, '<=', (int) $value),
                'ordered_since' => $this->applyOrderedSince($query, $storeId, (string) $value, true),
                'not_ordered_since' => $this->applyOrderedSince($query, $storeId, (string) $value, false),
                'has_abandoned_cart' => $this->applyAbandonedCart($query, $storeId),
                'min_loyalty_points' => $this->applyLoyaltyPoints($query, (int) $value),
                default => null,
            };
        }

        return $query;
    }

    /**
     * MKT-005 — at least this many loyalty points.
     *
     * TWO THINGS HAPPEN HERE AND BOTH ARE DECISIONS.
     *
     * 1. **AN UNEVALUABLE RULE NARROWS TO NOTHING, IT IS NEVER IGNORED.** With `RewardClubPoint`
     *    not installed the column is absent, and this method cannot answer. Skipping it would leave
     *    the segment as whatever the OTHER rules allow — so a merchant who asked for "at least 2
     *    orders AND at least 500 points" would be handed, and could message, every two-order
     *    customer in the store. That is exactly the failure this class's docblock forbids for an
     *    empty definition ("the dangerous reading — no filter, so all customers"), so it gets the
     *    same answer: `1 = 0`, a literal with no input.
     *
     * 2. **A NULL BALANCE IS ZERO POINTS, AND SQL DOES NOT THINK SO.** A customer who never earned
     *    holds NULL, and `NULL >= 0` is NULL, i.e. excluded — so "at least zero points" would omit
     *    precisely the customers it plainly includes. That is the `max_orders` lesson in another
     *    column (`whereHas(..., '<=', N)` needs a related row to be false about), and it is handled
     *    the same way: explicitly, at the one threshold where it can matter. `orWhereNull` rather
     *    than a `COALESCE` fragment, because this class takes no SQL text from anywhere.
     */
    private function applyLoyaltyPoints(Builder $query, int $points): void
    {
        if (! self::capabilityMet(self::RULES['min_loyalty_points'])) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($inner) use ($points): void {
            $inner->where('customers.total_club_point', '>=', $points);

            if ($points <= 0) {
                $inner->orWhereNull('customers.total_club_point');
            }
        });
    }

    /**
     * Orders counted in THIS store only, and `is_guest = 0` on purpose.
     *
     * `orders.customer_id` is an `integer` with a DEFAULT OF 0 and a sibling `is_guest` flag, so a
     * guest checkout carries a customer_id that is not a customer. Counting guest rows would
     * attribute a stranger's order to whichever customer happens to hold that id -- and customer
     * ids are reused across tenants in a shared database, which is how a cross-tenant read has
     * happened in this codebase before.
     *
     * A CORRELATED SUBQUERY AND NOT `whereHas`, because `Customer` DECLARES NO `orders` RELATION --
     * checked, not assumed: the model has no `orders()`, `carts()` or `wishlist()` method at all, so
     * `whereHas('orders', ...)` would throw. The subquery also puts the `store_id` predicate in
     * plain sight next to the join condition, which is the shape this codebase keeps getting wrong
     * when a predicate sits on the far side of a relation.
     */
    private function orderCount(int $storeId): \Closure
    {
        return static function ($sub) use ($storeId): void {
            $sub->selectRaw('count(*)')
                ->from('orders')
                ->whereColumn('orders.customer_id', 'customers.id')
                ->where('orders.store_id', $storeId)
                ->where('orders.is_guest', 0);
        };
    }

    private function applyOrderCount(Builder $query, int $storeId, string $operator, int $count): void
    {
        if ($operator === '>=' && $count <= 0) {
            /*
             * "At least 0 orders" is every customer in the store. Returning early rather than
             * adding a predicate that is always true keeps the intent readable -- and the rule is
             * still honoured, because the store predicate is applied by `applyTo()` regardless.
             */
            return;
        }

        /*
         * A COUNT COMPARISON, not an existence test, and the difference decides whether "at most N
         * orders" includes the people who have never ordered. `whereHas(..., '<=', N)` requires at
         * least one related row, so it would silently skip exactly the customers a merchant most
         * wants to reach. A count subquery answers 0 for them and 0 <= N is true.
         *
         * `$operator` is one of two literals chosen by `applyTo()`'s `match`, never caller input.
         */
        $query->where($this->orderCount($storeId), $operator, $count);
    }

    private function applyOrderedSince(Builder $query, int $storeId, string $date, bool $has): void
    {
        $recent = static function ($sub) use ($storeId, $date): void {
            $sub->selectRaw('1')
                ->from('orders')
                ->whereColumn('orders.customer_id', 'customers.id')
                ->where('orders.store_id', $storeId)
                ->where('orders.is_guest', 0)
                ->whereDate('orders.order_date', '>=', $date);
        };

        /*
         * `whereNotExists` FOR THE NEGATIVE, which is what makes "has NOT ordered since" include a
         * customer with no orders at all. A `whereDate(..., '<', $date)` would not: it needs a row
         * to be false about, so it would quietly report a store's dormant customers as active.
         */
        $has ? $query->whereExists($recent) : $query->whereNotExists($recent);
    }

    private function applyAbandonedCart(Builder $query, int $storeId): void
    {
        /*
         * `carts` is the basket table and a row in it IS a basket left behind -- the merchant's
         * abandoned-cart screen reads the same rows. Store-scoped explicitly: `Cart` is allowlisted
         * in `config/tenancy.php`, but the scope adds no predicate when no tenant resolves, and
         * `carts.customer_id` is an `integer` whose ids are reused across tenants.
         */
        $query->whereExists(static function ($sub) use ($storeId): void {
            $sub->selectRaw('1')
                ->from('carts')
                ->whereColumn('carts.customer_id', 'customers.id')
                ->where('carts.store_id', $storeId);
        });
    }

    /** A count may be 0 — "has never ordered" is a real rule — but never negative. */
    private static function nonNegativeInt($value): ?int
    {
        if (is_bool($value) || $value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $count = (int) $value;

        // An upper bound as well: a count in the millions is a typo, not a segment.
        return ($count >= 0 && $count <= 100000) ? $count : null;
    }

    /**
     * A calendar date, normalised, or null.
     *
     * `Carbon::createFromFormat('Y-m-d', ...)` fills the unspecified time components FROM THE
     * CURRENT CLOCK — measured in this repository at 14:22:48, where `2026-11-18` parsed as
     * `2026-11-18 14:22:48`, so an un-normalised bound moved through the day and a filter's answer
     * depended on when the merchant looked. `startOfDay()` and a strict format check both matter.
     */
    private static function date($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        /*
         * Carbon ROLLS a date like `2026-02-30` forward to 1 March rather than refusing it, so the
         * round trip is compared: a value that does not spell itself back was not a date.
         */
        return $date->toDateString() === $value ? $date->startOfDay()->toDateString() : null;
    }
}
