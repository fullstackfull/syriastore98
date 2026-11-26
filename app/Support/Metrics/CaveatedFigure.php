<?php

namespace App\Support\Metrics;

use ArrayAccess;
use InvalidArgumentException;
use JsonSerializable;

/**
 * GAP-152 / P05-04 — A FIGURE AND ITS CAVEATS, AS ONE OBJECT. The words are DERIVED, never passed.
 * =================================================================================================
 *
 * The gap register's next action for this row is the design, and it is a distinction rather than a
 * preference: "Design the caveat carrier as part of the metric object so a screen cannot render a
 * figure without it, rather than adding caveats per-view." A per-view caveat is one somebody forgets
 * on the next view. This class is the carrier, and the property that makes it one is that
 * `hint()` is COMPUTED FROM `caveats()`: there is no constructor parameter holding the sentence a
 * merchant reads, so no call site can produce a figure whose words omit a caveat the metric
 * attached.
 *
 * WHAT THE PER-VIEW MECHANISM COST, MEASURED 2026-11-25 AND NOT INFERRED. `StoreRevenueMargin::for()`
 * attaches `MetricCaveat::EXCLUDES_PAYMENT_FEES` UNCONDITIONALLY — ANA-006: this product records no
 * gateway fee anywhere, so for every store that takes card payments the margin is overstated by the
 * gateway's cut. That one `MetricResult` is the `Estimated gross profit` tile on **three** screens
 * (`/finance`, `/analytics`, `reports/finance_report`) and in **two** export paths (the `/analytics`
 * CSV and the scheduled-report mail). The sentence was written, translated into Arabic and ratcheted
 * by `MetricCaveatMessagesAreTranslatedTest` — and a grep of `resources/views/` and `app/` for it
 * returned exactly one hit, a docblock. Five surfaces rendered the figure; none rendered the caveat,
 * because each hint was composed by hand in a different file from the metric that knew about it.
 *
 * THE OTHER HALF: A CARRIER THAT ONLY APPENDS THE GENERIC SENTENCE WOULD BE WRONG, and that is
 * measured too. Two caveats on that same tile must NOT be printed in their own words:
 *
 *   * `EXCLUDES_SHIPPING_AND_TAX` reads "Margin excludes shipping and tax", which is false for a
 *     store whose prices include tax — recorded as GAP-213(d) in `StoreRevenueMargin`'s docblock,
 *     and `FinanceLedgerFiguresTest` asserts that sentence stays off the tile.
 *   * `COST_UNKNOWN` reads "...for some ORDERS ... those ROWS" — the right machine key and the
 *     wrong noun for a surface counting products. `PHASE-05-METRIC-DEFINITIONS.md` §16 says so in
 *     terms, and warns that "a future consumer that prints `caveatMessages()` raw" would show it.
 *
 * So a caveat is answered one of exactly two ways, and silence is not one of them:
 *
 *   1. by its own `MetricCaveat::message()` — the DEFAULT, so forgetting produces a slightly
 *      generic sentence rather than nothing at all; or
 *   2. by a sentence the surface DECLARES for that key in `$statedAs`, which must be non-empty and
 *      must name a caveat the figure actually carries. Both refusals are deliberate: an empty
 *      statement is how a caveat would be dropped under cover of being "handled", and a statement
 *      for an absent caveat is dead text that drifts the moment the metric changes.
 *
 * The caveat KEYS survive the discharge (`caveats()` is unchanged by `statedAs`), so an export, a
 * report or a test can still see that the figure is qualified and by what, whichever words were
 * chosen. A caveat is a machine key, never a sentence — the split `MetricCaveat` already draws.
 *
 * WHY IT ALSO ANSWERS ARRAY READS. Three Blades and `FigureCsv` read
 * `$figure['value'|'period'|'hint']`, and the figures classes return array-shaped tiles. Making the
 * carrier answer those reads is what lets the object travel the existing paths unchanged, so the
 * mechanism could be wired to real surfaces in one slice instead of rewriting every consumer first.
 * It is a bridge and it is deliberately read-only: `offsetSet()` and `offsetUnset()` throw, because
 * a surface that could overwrite `hint` would be back to a per-view caveat with extra steps.
 *
 * NOTHING HERE COMPUTES A FIGURE. It formats no money, reads no table, converts no currency and
 * makes no inclusion decision. It is handed a value a metric already produced and the caveats that
 * metric already attached.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class CaveatedFigure implements ArrayAccess, JsonSerializable
{
    /**
     * @param  list<string>  $meaning   already-translated definition sentences, in reading order
     * @param  list<string>  $caveats   machine keys, in the order the metric attached them
     * @param  array<string, string>  $statedAs  caveat key => the sentence this surface answers it with
     */
    private function __construct(
        private readonly ?string $value,
        private readonly string $period,
        private readonly array $meaning,
        private readonly array $caveats,
        private readonly array $statedAs,
        private readonly bool $authoritative,
        private readonly bool $single = true,
    ) {
    }

    /**
     * THE PRIMARY DOOR: a figure built from the metric that produced it.
     *
     * There is no caveat parameter, and that absence is the design. The caveats come off the
     * `MetricResult` — including the ones `MetricResult::make()` attaches on the data's behalf
     * (`MIXED_CURRENCY`) and the one `assumingUnknownCurrencyIs()` adds (`CURRENCY_UNRECORDED`) —
     * so a surface cannot hand in a shorter list than the metric's, by oversight or otherwise.
     *
     * @param  ?string  $value    the formatted figure, or null for "we cannot say"
     * @param  list<string>  $meaning
     * @param  array<string, string>  $statedAs
     */
    public static function from(
        MetricResult $result,
        ?string $value,
        string $period,
        array $meaning = [],
        array $statedAs = [],
    ): self {
        return self::build($value, $period, $result->caveats(), $meaning, $statedAs, $result->isAuthoritative());
    }

    /**
     * The same, for a metric that is not a `MetricResult`.
     *
     * `StoreInventoryValuation` is the live case: §16 records why it measures none of §11 rule 1's
     * three records and is therefore not a `Metric`, while keeping its own `MetricScope`,
     * `MetricWindow`, `MetricCaveat` keys and `notes()`. Its caveat list is passed here.
     *
     * THE LIST MUST BE THE METRIC'S OWN, WHOLE. Hand-picking a subset per tile is the per-view
     * omission this class exists to prevent, wearing the carrier's clothes; if a caveat does not
     * fit a figure's wording, answer it in `$statedAs`, which is visible and reviewable.
     *
     * @param  list<string>  $caveats
     * @param  list<string>  $meaning
     * @param  array<string, string>  $statedAs
     */
    public static function of(
        ?string $value,
        string $period,
        array $caveats = [],
        array $meaning = [],
        array $statedAs = [],
    ): self {
        $authoritative = true;

        foreach ($caveats as $caveat) {
            if (MetricCaveat::exists($caveat) && MetricCaveat::isBlocking($caveat)) {
                $authoritative = false;
            }
        }

        return self::build($value, $period, $caveats, $meaning, $statedAs, $authoritative);
    }

    /**
     * A DISCLOSURE FOR SEVERAL FIGURES AT ONCE, which is a real shape and not a convenience.
     *
     * `StoreInventoryValuation` produces SEVEN figures from one read and attaches ONE caveat list
     * to the set -- `MONEY_FLOAT_PRECISION` because `products.price`, `sale_price` and the two
     * variant price columns are `double` (measured on MariaDB, §16), and `COST_UNKNOWN` when a
     * valued product records no cost. `notes()['float_precision_figures']` and `exact_figures` then
     * name which figures each applies to: the cost total is summed from `decimal(15,2)` and is
     * exact.
     *
     * Forcing that onto one `from()` carrier would mean either attaching the set's caveats to a
     * figure they do not all qualify, or letting the surface pick a subset per tile -- and the
     * subset is the per-view omission this class exists to prevent. So the set gets one carrier,
     * with no value of its own, and `isSingleFigure()` is false so a tile component can refuse it
     * rather than print "Not available" where a number belongs.
     *
     * @param  list<string>  $caveats  the metric's own list, whole
     * @param  list<string>  $meaning
     * @param  array<string, string>  $statedAs
     */
    public static function forFigures(
        string $period,
        array $caveats,
        array $meaning = [],
        array $statedAs = [],
    ): self {
        return self::of(null, $period, $caveats, $meaning, $statedAs)->asFigureSet();
    }

    private function asFigureSet(): self
    {
        return new self(
            null, $this->period, $this->meaning, $this->caveats, $this->statedAs, $this->authoritative, false
        );
    }

    /**
     * False for a disclosure covering several figures, which has no one value to render.
     *
     * `<x-ss.figure>` asks this before rendering a tile: "Not available" in a tile means "we cannot
     * produce this figure", and a set's carrier would be claiming that about figures the screen is
     * printing a few pixels away.
     */
    public function isSingleFigure(): bool
    {
        return $this->single;
    }

    /**
     * @param  list<string>  $caveats
     * @param  list<string>  $meaning
     * @param  array<string, string>  $statedAs
     */
    private static function build(
        ?string $value,
        string $period,
        array $caveats,
        array $meaning,
        array $statedAs,
        bool $authoritative,
    ): self {
        $caveats = array_values(array_unique($caveats));

        foreach ($caveats as $caveat) {
            if (! MetricCaveat::exists($caveat)) {
                throw new InvalidArgumentException('Unknown metric caveat: ' . $caveat);
            }
        }

        foreach ($statedAs as $caveat => $sentence) {
            if (! in_array($caveat, $caveats, true)) {
                throw new InvalidArgumentException(
                    'This surface states the caveat "' . $caveat . '" and the figure does not carry it, so the '
                    . 'sentence would be dead text. Carried: '
                    . ($caveats === [] ? 'none' : implode(', ', $caveats)) . '.'
                );
            }

            if (trim((string) $sentence) === '') {
                throw new InvalidArgumentException(
                    'A caveat cannot be answered with silence: "' . $caveat . '" was given an empty sentence. '
                    . 'Leave it out to show MetricCaveat::message(), or write what this surface says instead.'
                );
            }
        }

        return new self(
            $value,
            $period,
            array_values(array_filter(array_map('strval', $meaning), static fn (string $s): bool => trim($s) !== '')),
            $caveats,
            array_map('strval', $statedAs),
            $authoritative,
        );
    }

    /** The formatted figure, or null for "we cannot say" — which must never render as a zero. */
    public function value(): ?string
    {
        return $this->value;
    }

    /** The period this figure covers, stated. A figure with no period is how a dashboard misleads. */
    public function period(): string
    {
        return $this->period;
    }

    /** @return list<string> the machine keys, whichever words answered them */
    public function caveats(): array
    {
        return $this->caveats;
    }

    public function hasCaveat(string $caveat): bool
    {
        return in_array($caveat, $this->caveats, true);
    }

    /** False only for a BLOCKING caveat: an ordinary caveat qualifies a figure, a blocking one disqualifies it. */
    public function isAuthoritative(): bool
    {
        return $this->authoritative;
    }

    /**
     * One sentence per caveat, in the order the metric attached them.
     *
     * @return list<string>
     */
    public function caveatSentences(): array
    {
        return array_map(
            fn (string $caveat): string => $this->statedAs[$caveat] ?? MetricCaveat::message($caveat),
            $this->caveats
        );
    }

    /**
     * EVERYTHING A MERCHANT READS BESIDE THE FIGURE: what it means, then what it leaves out.
     *
     * Duplicates are collapsed. One sentence can legitimately answer two caveats, and a surface's
     * answer to a caveat can be a sentence already in `$meaning` — `FinanceLedgerFigures` answers
     * `EXCLUDES_SHIPPING_AND_TAX` with the tile's own definition, which is a single existing
     * translation key covering both. Declaring it records WHERE the caveat is discharged; the
     * reader should still see it once.
     */
    public function hint(): string
    {
        $sentences = array_merge($this->meaning, $this->caveatSentences());

        return implode(' ', array_values(array_unique($sentences)));
    }

    /**
     * The wire form, and a superset of the array the figures classes used to return.
     *
     * `caveats` is the addition, and it is what lets a gate assert the mechanism GENERICALLY: a
     * test can read the machine keys off a rendered payload and require each one's sentence on the
     * screen and in the file, instead of hard-coding a list that goes stale the next time a metric
     * attaches something.
     *
     * @return array{value: ?string, period: string, hint: string, caveats: list<string>, authoritative: bool}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'period' => $this->period,
            'hint' => $this->hint(),
            'caveats' => $this->caveats,
            'authoritative' => $this->authoritative,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[(string) $offset] ?? null;
    }

    /** A carrier is immutable: a surface that could overwrite `hint` is a per-view caveat again. */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('A CaveatedFigure cannot be modified: its hint is derived from its caveats.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('A CaveatedFigure cannot be modified: its hint is derived from its caveats.');
    }
}
