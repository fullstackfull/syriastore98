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
            // A null currency_code is a real state in this schema -- the column is nullable on
            // store_order_financials, store_wallets and store_wallet_transactions alike. It is
            // kept as a distinct bucket rather than folded into the store's configured currency,
            // because guessing here is how a figure acquires a unit it was never measured in.
            $key = ($currency === '' || $currency === null) ? 'unknown' : (string) $currency;

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

    public function totalIn(string $currency): float
    {
        return (float) ($this->byCurrency[$currency] ?? 0.0);
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
