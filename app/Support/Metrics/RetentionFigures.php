<?php

namespace App\Support\Metrics;

/**
 * THE RESULT OF A RETENTION MEASURE, which is counts and a ratio rather than money.
 *
 * `MetricResult` is deliberately not reused here. That class holds amounts PER CURRENCY and its
 * whole discipline -- `total()` refusing to add two currencies, `assumingUnknownCurrencyIs()`,
 * the mixed-currency caveat -- is about money having a unit. A customer count has no currency,
 * and squeezing one into a `byCurrency` map would mean inventing a bucket name and carrying
 * machinery that cannot apply. Two shapes, each honest about what it is.
 *
 * What it shares with `MetricResult` is the part that matters: it names the record it measures,
 * the column it is dated by, the caveats a surface must display, and the choices the metric made
 * that a reader could not otherwise infer.
 */
final class RetentionFigures
{
    /**
     * @param string[]             $caveats
     * @param array<string, mixed> $notes
     */
    public function __construct(
        private readonly string $metric,
        private readonly string $record,
        private readonly ?string $datedBy,
        private readonly int $identifiedCustomers,
        private readonly int $repeatCustomers,
        private readonly int $ordersCounted,
        private readonly MetricScope $scope,
        private readonly MetricWindow $window,
        private readonly array $caveats = [],
        private readonly array $notes = [],
    ) {
    }

    public function metric(): string
    {
        return $this->metric;
    }

    public function record(): string
    {
        return $this->record;
    }

    public function datedBy(): ?string
    {
        return $this->datedBy;
    }

    /** Customers with at least one counted order. Guests are never among them. */
    public function identifiedCustomers(): int
    {
        return $this->identifiedCustomers;
    }

    /** Customers with at least TWO counted orders. */
    public function repeatCustomers(): int
    {
        return $this->repeatCustomers;
    }

    public function ordersCounted(): int
    {
        return $this->ordersCounted;
    }

    /**
     * The share of identified customers who ordered more than once, as a fraction of 1.
     *
     * NULL rather than 0.0 when there are no identified customers at all. A store with no
     * customers in the window has no repeat-purchase rate; reporting 0% would say its customers
     * did not come back, which is a different and false claim. Every surface rendering this has
     * to handle the null, which is the point.
     */
    public function repeatRate(): ?float
    {
        if ($this->identifiedCustomers === 0) {
            return null;
        }

        return round($this->repeatCustomers / $this->identifiedCustomers, 4);
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

    /** @return string[] */
    public function caveatMessages(): array
    {
        return array_map(
            static fn (string $caveat): string => MetricCaveat::message($caveat),
            $this->caveats
        );
    }

    public function isAuthoritative(): bool
    {
        foreach ($this->caveats as $caveat) {
            if (MetricCaveat::isBlocking($caveat)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
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
            'identified_customers' => $this->identifiedCustomers,
            'repeat_customers' => $this->repeatCustomers,
            'repeat_rate' => $this->repeatRate(),
            'orders_counted' => $this->ordersCounted,
            'caveats' => $this->caveats,
            'authoritative' => $this->isAuthoritative(),
            'notes' => $this->notes,
            'scope' => $this->scope->describe(),
            'window' => $this->window->describe(),
        ];
    }
}
