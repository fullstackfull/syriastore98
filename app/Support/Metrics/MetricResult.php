<?php

namespace App\Support\Metrics;

use InvalidArgumentException;

/**
 * ONE FIGURE, WITH EVERYTHING NEEDED TO READ IT HONESTLY.
 *
 * `PHASE-05-METRIC-DEFINITIONS.md` section 11 rule 1: "Every figure states whether it measures
 * settlement (`orders`), entitlement (`store_order_financials`) or movement
 * (`store_wallet_transactions`)." Those three records disagree with one another by design -- a
 * cancelled-but-captured order is money in the movement record and a reversal in the entitlement
 * record -- so a figure that does not say which one it came from cannot be reconciled against
 * anything, and two such figures on one screen will look like a bug.
 *
 * The amounts are held PER CURRENCY and never summed across them. See MixedCurrencyException.
 */
final class MetricResult
{
    /** Measured on `orders`: what the customer was asked for. */
    public const RECORD_SETTLEMENT = 'settlement';

    /** Measured on `store_order_financials`: what the merchant is owed. */
    public const RECORD_ENTITLEMENT = 'entitlement';

    /** Measured on `store_wallet_transactions` / `store_wallets`: what actually moved. */
    public const RECORD_MOVEMENT = 'movement';

    private const RECORDS = [
        self::RECORD_SETTLEMENT,
        self::RECORD_ENTITLEMENT,
        self::RECORD_MOVEMENT,
    ];

    /**
     * @param array<string, float> $byCurrency
     * @param string[]             $caveats
     * @param array<string, mixed> $notes
     */
    private function __construct(
        private readonly string $metric,
        private readonly string $record,
        private readonly ?string $datedBy,
        private readonly array $byCurrency,
        private readonly int $rowCount,
        private readonly array $caveats,
        private readonly array $notes,
        private readonly MetricScope $scope,
        private readonly MetricWindow $window,
    ) {
    }

    /**
     * @param array<string, float|int|string> $byCurrency currency code => amount
     * @param string[]                        $caveats
     * @param array<string, mixed>            $notes      stated options, e.g. cancellations: excluded
     */
    public static function make(
        string $metric,
        string $record,
        ?string $datedBy,
        array $byCurrency,
        int $rowCount,
        MetricScope $scope,
        MetricWindow $window,
        array $caveats = [],
        array $notes = [],
    ): self {
        if (! in_array($record, self::RECORDS, true)) {
            throw new InvalidArgumentException(
                'A metric must name the record it measures, one of: ' . implode(', ', self::RECORDS)
                . '. Received "' . $record . '".'
            );
        }

        $amounts = [];

        foreach ($byCurrency as $currency => $amount) {
            /*
             * A null currency_code is a real state in this schema -- the column is nullable on
             * store_order_financials, store_wallets and store_wallet_transactions alike. It is
             * kept as a distinct bucket rather than folded into the store's configured currency,
             * because guessing here is how a figure acquires a unit it was never measured in.
             *
             * FIN-SYM: AND SO IS A VALUE THAT IS NOT A CURRENCY CODE. This keyed by the raw column
             * for anything non-empty, so any string became a currency of its own. P5-R14 MEASURED
             * that `Api\ApiController::place_order()` and `::ordersave()` stamped
             * `orders.currency_code` from the store's SYMBOL setting, and owner decision D-N34
             * leaves the rows already written in place, so a store whose orders came through both
             * writers really does hold 'SYP' on some rows and 'ل.س' on others. Case alone did it
             * too: 'usd' and 'USD' are one currency and were two buckets.
             *
             * WHAT THAT COST WAS NOT AN EXCEPTION, and saying so would be wrong: `total()` throws
             * on more than one bucket, but every shipped caller checks the bucket count first
             * (`MerchantAnalyticsFigures`, `FinanceLedgerFigures`, `WalletEntitlementFigure`,
             * `MetricThreshold`). So the figure was SUPPRESSED OR SPLIT -- a one-currency store's
             * tile refusing to show one number, the wallet surface offering the merchant a SYMBOL
             * as a currency beside its own code, and `MetricThreshold` skipping the comparison so
             * a real floor breach went unreported.
             *
             * NOTHING IS CONVERTED HERE AND NOTHING IS GUESSED. A value that is not a code means
             * the writer recorded no currency, which is the state this class already names: it
             * joins `unknown`, and `assumingUnknownCurrencyIs()` folds that into the store's own
             * currency ONLY when a caller opts in, carrying `CURRENCY_UNRECORDED` and the exact
             * amount assumed. Two values that ARE codes and normalise alike are summed, because
             * they are one currency -- which is currency IDENTITY, not currency conversion.
             *
             * `CurrencySymbol::code()` is the single definition of "is a currency code" in this
             * application (three ASCII letters, case folded, or ''), and it is called rather than
             * re-implemented so this bucket key and the operator panel's cannot drift apart.
             */
            $code = \App\Support\Money\CurrencySymbol::code($currency === null ? '' : (string) $currency);

            $key = $code === '' ? self::UNKNOWN_CURRENCY : $code;

            $amounts[$key] = round(($amounts[$key] ?? 0.0) + (float) $amount, 2);
        }

        foreach ($caveats as $caveat) {
            if (! MetricCaveat::exists($caveat)) {
                throw new InvalidArgumentException('Unknown metric caveat: ' . $caveat);
            }
        }

        $caveats = array_values(array_unique($caveats));

        // Mixed currency is a property of the DATA, not a judgement the metric makes, so it is
        // detected here rather than trusted to each metric to remember.
        if (count($amounts) > 1 && ! in_array(MetricCaveat::MIXED_CURRENCY, $caveats, true)) {
            $caveats[] = MetricCaveat::MIXED_CURRENCY;
        }

        return new self(
            $metric, $record, $datedBy, $amounts, $rowCount, $caveats, $notes, $scope, $window
        );
    }

    public function metric(): string
    {
        return $this->metric;
    }

    public function record(): string
    {
        return $this->record;
    }

    /** The column this figure is dated by, or null for a running balance. */
    public function datedBy(): ?string
    {
        return $this->datedBy;
    }

    /** How many source rows produced this figure. Zero is a real answer, not a failure. */
    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /** @return array<string, float> */
    public function byCurrency(): array
    {
        return $this->byCurrency;
    }

    /** @return string[] */
    public function currencies(): array
    {
        return array_keys($this->byCurrency);
    }

    public function isSingleCurrency(): bool
    {
        return count($this->byCurrency) <= 1;
    }

    public function currency(): ?string
    {
        return $this->isSingleCurrency()
            ? (array_key_first($this->byCurrency) ?: null)
            : null;
    }

    /**
     * The one number -- available only when there IS one number.
     *
     * @throws MixedCurrencyException when the rows span more than one currency
     */
    public function total(): float
    {
        if (! $this->isSingleCurrency()) {
            throw MixedCurrencyException::forMetric($this->metric, $this->currencies());
        }

        // `reset()` takes its array BY REFERENCE to move the internal pointer, which PHP refuses
        // on a readonly property -- "Cannot modify readonly property". Indexing a copy does not.
        $amounts = array_values($this->byCurrency);

        return $amounts === [] ? 0.0 : (float) $amounts[0];
    }

    /**
     * THE CALLER'S SPELLING IS NORMALISED TOO, because the bucket keys are (FIN-SYM). A caller
     * asking in lower case was told the store held nothing in that currency, which is the same
     * defect as the split bucket reaching the reader by a different route.
     *
     * `UNKNOWN_CURRENCY` STAYS REACHABLE BY NAME. It is the one key that is deliberately not a
     * currency code, so it is matched before normalisation -- `code('unknown')` is '' and would
     * otherwise send every unrecognised request to it, turning "how much is in XYZ" into "how much
     * is in no currency at all".
     */
    public function totalIn(string $currency): float
    {
        if ($currency === self::UNKNOWN_CURRENCY) {
            return (float) ($this->byCurrency[self::UNKNOWN_CURRENCY] ?? 0.0);
        }

        $code = \App\Support\Money\CurrencySymbol::code($currency);

        return $code === '' ? 0.0 : (float) ($this->byCurrency[$code] ?? 0.0);
    }

    /** The bucket `Metric::sumByCurrency()` puts a null or empty `currency_code` in. */
    public const UNKNOWN_CURRENCY = 'unknown';

    /**
     * THE ONE-NUMBER ANSWER FOR A TILE, WITHOUT INVENTING ANYTHING.
     *
     * Folds the `unknown` currency bucket into the named currency and returns a NEW result
     * carrying `MetricCaveat::CURRENCY_UNRECORDED` plus the AMOUNT that was assumed. The original
     * is untouched.
     *
     * WHY THIS HAD TO EXIST. `2026_10_12_000102` added `orders.currency_code` and deliberately did
     * not backfill -- its own note says the correct per-order historical rate is not recoverable
     * from code and that "reading code must tolerate NULL on pre-migration orders". So every store
     * with history has an `unknown` bucket, `MetricResult::make()` attaches MIXED_CURRENCY, and
     * `total()` throws. A merchant report tile renders one number beside one currency symbol, so
     * the obvious wiring -- `->total()` into a Blade tile -- is an uncaught exception on
     * `GET order-reports-chart`. That is not a caveat, it is an HTTP 500 on a merchant route.
     *
     * WHAT IS AND IS NOT ASSUMED. Inventing a RATE is forbidden and nothing here converts: the
     * only assumption is that orders placed before the column existed were in the currency the
     * store uses now, which is true for every store that has not changed currency. That is narrow,
     * it is stated, and `notes()['currency_assumed_amount']` lets a merchant see whether it
     * matters. A store that HAS changed currency gets a wrong figure from this -- which is why the
     * caveat is attached unconditionally whenever anything was folded, and why this method is
     * opt-in rather than the behaviour of `total()`.
     *
     * IT DOES NOT MAKE A GENUINELY MULTI-CURRENCY RESULT SINGLE. Folding `unknown` into USD on a
     * store that also trades in SYP still leaves two buckets, MIXED_CURRENCY stays, and `total()`
     * still refuses. That is correct: the problem this solves is unrecorded currency, not two
     * real currencies.
     */
    public function assumingUnknownCurrencyIs(string $currency): self
    {
        /*
         * THE TARGET IS MERCHANT-WRITTEN TOO (FIN-SYM). Every caller passes the raw
         * `defult_currancy` setting -- `MerchantAnalyticsController::storeAndCurrency()` reads it
         * with `->value('value') ?? 'USD'` -- and FIN-CUR-2 fixed the WRITER while owner decision
         * D-N34 leaves existing rows alone. So the value handed in can itself be a symbol, and
         * folding the unrecorded rows into it would name a bucket with a symbol and print it to
         * the merchant as a currency: the same defect arriving by the other door.
         *
         * NOTHING IS ASSUMED when the caller names no currency, and the result says so by NOT
         * carrying `CURRENCY_UNRECORDED` -- the caller does not know the currency either, and the
         * caveat would claim an assumption that was never made. It does not THROW, because every
         * caller of this method is rendering a merchant tile and an exception there is the HTTP 500
         * this method exists to prevent.
         */
        $code = \App\Support\Money\CurrencySymbol::code($currency);

        if ($code === '') {
            return $this;
        }

        $currency = $code;

        $unknown = $this->byCurrency[self::UNKNOWN_CURRENCY] ?? null;

        if ($unknown === null) {
            // Nothing was assumed, so nothing is claimed. Returning `$this` rather than a copy
            // with an empty note keeps "this figure carries an assumption" and "it does not"
            // distinguishable by `hasCaveat()` alone.
            return $this;
        }

        $folded = $this->byCurrency;
        unset($folded[self::UNKNOWN_CURRENCY]);
        $folded[$currency] = round(($folded[$currency] ?? 0.0) + (float) $unknown, 2);

        $caveats = $this->caveats;

        // MIXED_CURRENCY was attached by make() because `unknown` was a second bucket. If folding
        // leaves one, the result is no longer mixed and must stop saying so -- a caveat that is
        // false is worse than one that is missing.
        if (count($folded) <= 1) {
            $caveats = array_values(array_filter(
                $caveats,
                static fn (string $c): bool => $c !== MetricCaveat::MIXED_CURRENCY
            ));
        }

        $caveats[] = MetricCaveat::CURRENCY_UNRECORDED;

        return self::make(
            $this->metric,
            $this->record,
            $this->datedBy,
            $folded,
            $this->rowCount,
            $this->scope,
            $this->window,
            array_values(array_unique($caveats)),
            array_merge($this->notes, [
                'currency_assumed_amount' => round((float) $unknown, 2),
                'currency_assumed_into' => $currency,
            ]),
        );
    }

    /**
     * How much of this figure is in no known currency. Zero when every row carries one.
     *
     * Exposed separately so a surface can decide whether the assumption is worth mentioning
     * prominently or at all -- an `unknown` amount of 0.00 on a store with no history needs no
     * explaining, and one that is most of the figure needs more than a caveat line.
     */
    public function unrecordedCurrencyAmount(): float
    {
        return (float) ($this->byCurrency[self::UNKNOWN_CURRENCY] ?? 0.0);
    }

    /** @return string[] */
    public function caveats(): array
    {
        return $this->caveats;
    }

    public function hasCaveat(string $caveat): bool
    {
        return in_array($caveat, $this->caveats, true);
    }

    /**
     * Whether this figure may be presented as fact.
     *
     * False only for a BLOCKING caveat -- today, section 9's coupon redemption, blocked by owner
     * instruction. An ordinary caveat qualifies a figure; a blocking one disqualifies it.
     */
    public function isAuthoritative(): bool
    {
        foreach ($this->caveats as $caveat) {
            if (MetricCaveat::isBlocking($caveat)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] the sentences a merchant should see beside this figure */
    public function caveatMessages(): array
    {
        return array_map(
            static fn (string $caveat): string => MetricCaveat::message($caveat),
            $this->caveats
        );
    }

    /**
     * The choices this metric made that a reader could not otherwise infer -- section 1's
     * "The report must label which it used. Never silently pick one."
     *
     * @return array<string, mixed>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    public function scope(): MetricScope
    {
        return $this->scope;
    }

    public function window(): MetricWindow
    {
        return $this->window;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'record' => $this->record,
            'dated_by' => $this->datedBy,
            'by_currency' => $this->byCurrency,
            'row_count' => $this->rowCount,
            'caveats' => $this->caveats,
            'authoritative' => $this->isAuthoritative(),
            'notes' => $this->notes,
            'scope' => $this->scope->describe(),
            'window' => $this->window->describe(),
        ];
    }
}
