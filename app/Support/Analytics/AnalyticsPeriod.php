<?php

namespace App\Support\Analytics;

use App\Support\Metrics\MetricWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ANA-002 — THE PERIOD A MERCHANT CHOSE, resolved from request input and never trusted as given.
 * =================================================================================================
 *
 * `/analytics` showed a fixed thirty days. ANA-002 asks for "comparisons, filters and saved
 * periods": the comparison shipped on 2026-11-21, and this is the filter. It is a value object so
 * that ONE place decides what a period means, and so the screen, the CSV export and the saved view
 * cannot disagree about which days a figure covers -- a figure labelled with the wrong period is
 * the same class of defect as a figure with the wrong number, and harder to notice.
 *
 * WHY THIS IS NOT A TENANCY RISK, stated plainly because CLAUDE.md records that caller-supplied
 * input has produced four separate tenant defects here. A period is NOT a tenant predicate: the
 * store still comes from `getCurrentStore()` and is never read from the request. What a caller
 * controls here is only WHICH OF THEIR OWN DAYS they look at, which is what a filter is. The
 * screen's docblock used to say it took no request input at all; that is no longer true, so it
 * says what it takes and why that is safe instead.
 *
 * EVERYTHING IS AN ALLOWLIST OR A CLAMP, AND NOTHING IS AN ERROR.
 *
 *   - A preset key not in `PRESETS` resolves to the default. It does not 422 and it does not throw:
 *     a merchant following a stale bookmark should see their analytics, not a validation page, and
 *     a screen that refuses to render is a worse outcome than a screen showing the default month.
 *   - A custom range is parsed STRICTLY as `Y-m-d` (`createFromFormat` with `!` and an exact-format
 *     check), so `2026-13-45`, `now`, `+1 day` and `2026-02-30` are all rejected rather than
 *     silently reinterpreted. Carbon's ordinary parser accepts relative English and would turn
 *     `?ss_to=yesterday` into a real date, which is a parser deciding product behaviour.
 *   - A reversed range is SWAPPED, not refused, because a date picker that lets you pick them in
 *     either order is a UI bug and a merchant should not be punished for it. The ordering is then
 *     RE-ASSERTED after the end-of-period clamp, because that clamp can reverse the window on its
 *     own -- which was a live 500 until an independent review found it.
 *   - The span is CAPPED at `MAX_SPAN_DAYS`, and the end is clamped to today. Both are disclosed
 *     through `clampNote()` rather than applied in silence: a window the system quietly shortened
 *     is a figure that does not cover the period its own label names.
 *
 * THE CAP IS A REAL COST, NOT A FORMALITY. `shetabit_visits` is indexed on `store_id` ALONE --
 * verified against the schema, nothing on `created_at` -- so a window is a scan of this store's
 * whole visit history whatever its bounds, and the referrer breakdown groups a MEDIUMTEXT column on
 * top of that. The cap bounds what one request can be asked to aggregate; it does not make the
 * query cheap, and the composite index that would is a persistent migration and therefore the
 * owner's decision.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: it does not define, compute or adjust any figure. It returns
 * a window and the words for it. Which rows a figure counts, and what it leaves out, stays in
 * `MerchantAnalyticsFigures` and `FinanceLedgerFigures` exactly as before.
 */
final class AnalyticsPeriod
{
    /** The request keys, which are ALSO the saved-view vocabulary for the `analytics` screen. */
    public const KEY_PERIOD = 'ss_period';

    public const KEY_FROM = 'ss_from';

    public const KEY_TO = 'ss_to';

    /** The preset the screen has always shown, so an unfiltered visit is byte-identical to before. */
    public const DEFAULT = 'last_30_days';

    public const CUSTOM = 'custom';

    /**
     * Two years. Long enough for a year-on-year look, short enough that one request cannot be made
     * to aggregate an unbounded history -- a caller can type any two dates, including 1900 and 2999.
     */
    public const MAX_SPAN_DAYS = 731;

    /**
     * THE WHOLE VOCABULARY OF PERIODS, as key => days-counted-back-inclusive.
     *
     * `null` means the preset is calendar-shaped rather than a rolling count and is resolved in
     * `windowFor()`. Adding a key here is the only way to add a period: `fromRequest()` consults
     * this list and nothing else, so there is no second place a value could enter from.
     */
    private const PRESETS = [
        'last_7_days' => 7,
        'last_30_days' => 30,
        'last_90_days' => 90,
        'last_365_days' => 365,
        'this_month' => null,
        'last_month' => null,
        'this_year' => null,
    ];

    private function __construct(
        private readonly string $key,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly ?string $clampNote,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the request's own query/body bag
     */
    public static function fromRequest(array $input, ?CarbonInterface $now = null): self
    {
        $now = $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);

        $requested = $input[self::KEY_PERIOD] ?? null;
        $requested = is_string($requested) ? trim($requested) : '';

        if ($requested === self::CUSTOM) {
            return self::custom(
                is_string($input[self::KEY_FROM] ?? null) ? $input[self::KEY_FROM] : '',
                is_string($input[self::KEY_TO] ?? null) ? $input[self::KEY_TO] : '',
                $now
            );
        }

        $key = array_key_exists($requested, self::PRESETS) ? $requested : self::DEFAULT;

        [$from, $to] = self::windowFor($key, $now);

        return new self($key, $from, $to, null);
    }

    /** The unfiltered screen, for callers with no request to read (the default export, tests). */
    public static function default(?CarbonInterface $now = null): self
    {
        return self::fromRequest([], $now);
    }

    /**
     * A caller-typed range, which is where every guard earns its place.
     *
     * An unparseable bound falls back to the DEFAULT PRESET rather than to "all time": a merchant
     * who typed something wrong gets the screen they are used to, not the slowest query in the
     * application.
     */
    private static function custom(string $rawFrom, string $rawTo, CarbonImmutable $now): self
    {
        $from = self::strictDate($rawFrom);
        $to = self::strictDate($rawTo);

        if ($from === null || $to === null) {
            [$defaultFrom, $defaultTo] = self::windowFor(self::DEFAULT, $now);

            return new self(self::DEFAULT, $defaultFrom, $defaultTo, null);
        }

        $notes = [];

        // A picker that allows either order is a UI bug; do not make the merchant pay for it.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $today = $now->endOfDay();

        if ($to->greaterThan($today)) {
            $to = $today;
            $notes[] = __('The end of the period was moved to today, because there is nothing recorded after today.');
        }

        /*
         * THE ORDERING IS RE-ASSERTED AFTER THE CLAMP, because the clamp can itself reverse the
         * window. This was a live HTTP 500, found by an independent review and reproduced here
         * before fixing.
         *
         * The chain: a merchant picks TOMORROW in both date fields. The swap above does nothing --
         * the two are equal -- and then `$to` is clamped back to today while `$from` is left in the
         * future, so `from > to`. The span check below did not catch it either, because Carbon 2's
         * `diffInDays()` is ABSOLUTE: it reported 1 day for a window that runs backwards, so the
         * cap saw nothing wrong. `MetricWindow::between()` then threw
         * `InvalidArgumentException: A metric window ends before it starts`, uncaught, and the
         * screen answered 500. Reachable by clicking: the form offered two bare `type="date"`
         * inputs with no `max`.
         *
         * MOVING `$from` TO THE START OF `$to`'s DAY is the honest answer rather than a refusal.
         * The merchant asked for a day that has not happened; the nearest true period is today, and
         * it is DISCLOSED like every other adjustment. Refusing would be a validation page in place
         * of a screen, which this class exists not to do.
         */
        if ($from->greaterThan($to)) {
            $from = $to->startOfDay();
            $notes[] = __('The start of the period was moved to today, because there is nothing recorded after today.');
        }

        $from = $from->startOfDay();
        $to = $to->endOfDay();

        /*
         * THE SPAN IS COMPUTED SIGN-AWARE AND THEN GUARDED, rather than trusting `diffInDays()` to
         * be positive. Carbon 2's default is absolute and Carbon 3's is signed, so a dependency
         * bump would silently change which branch this takes -- and the absolute reading is exactly
         * what hid the reversed window above. `max(0, ...)` makes a reversed span read as zero,
         * which cannot exceed the cap, so the cap is never the thing that repairs an ordering bug.
         *
         * The inclusive `+1` is deliberate: 1 Jan to 1 Jan is ONE day, not zero. Getting that wrong
         * by one is how a cap silently rejects a legitimate exactly-at-the-limit range, which is the
         * same off-by-one the referrer bound had.
         */
        $span = max(0, (int) $from->diffInDays($to, false)) + 1;

        if ($span > self::MAX_SPAN_DAYS) {
            $from = $to->subDays(self::MAX_SPAN_DAYS - 1)->startOfDay();
            $notes[] = __('A period longer than :count days was shortened to the most recent :count days.', [
                'count' => self::MAX_SPAN_DAYS,
            ]);
        }

        return new self(self::CUSTOM, $from, $to, $notes === [] ? null : implode(' ', $notes));
    }

    /**
     * STRICT `Y-m-d` ONLY. `CarbonImmutable::parse()` accepts `yesterday`, `+1 week` and `now`,
     * which would let a URL carry a relative period the product never offered; and it accepts
     * `2026-02-30`, rolling it to 2 March, so a typo becomes a different real period rather than a
     * rejected one. `createFromFormat('!Y-m-d')` with a round-trip check rejects all of that.
     */
    private static function strictDate(string $raw): ?CarbonImmutable
    {
        $raw = trim($raw);

        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $raw);

        if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
            return null;
        }

        return $parsed;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function windowFor(string $key, CarbonImmutable $now): array
    {
        return match ($key) {
            'this_month' => [$now->startOfMonth()->startOfDay(), $now->endOfDay()],
            'last_month' => [
                $now->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                $now->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            'this_year' => [$now->startOfYear()->startOfDay(), $now->endOfDay()],
            default => [
                $now->subDays((self::PRESETS[$key] ?? 30) - 1)->startOfDay(),
                $now->endOfDay(),
            ],
        };
    }

    public function key(): string
    {
        return $this->key;
    }

    public function from(): CarbonImmutable
    {
        return $this->from;
    }

    public function to(): CarbonImmutable
    {
        return $this->to;
    }

    public function window(): MetricWindow
    {
        return MetricWindow::between($this->from, $this->to);
    }

    /** The inclusive number of days this period covers, used for the baseline's own label. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * THE WORDS UNDER EVERY FIGURE. A rolling preset keeps the wording the screen has always used
     * so nothing a merchant already reads changes; a calendar preset names the month or year,
     * because "last 30 days" would be a FALSE LABEL on a window that is 28 days long.
     */
    public function label(): string
    {
        return match ($this->key) {
            'this_month' => __('this month (:month)', ['month' => $this->from->isoFormat('MMMM YYYY')]),
            'last_month' => __('last month (:month)', ['month' => $this->from->isoFormat('MMMM YYYY')]),
            'this_year' => __('this year (:year)', ['year' => $this->from->isoFormat('YYYY')]),
            self::CUSTOM => __(':from to :to', [
                'from' => $this->from->toDateString(),
                'to' => $this->to->toDateString(),
            ]),
            default => __('last :count days', ['count' => number_format($this->days())]),
        };
    }

    /**
     * The comparison baseline's words.
     *
     * ONE SENTENCE FOR EVERY PERIOD SHAPE, and deliberately so. `MetricWindow::previous()` returns
     * the equally long window ending the day before, whatever shape the chosen period had -- the
     * baseline for "last month" is the 31 days before it, NOT the calendar month before it. Saying
     * "the 31 days before that" is therefore the true description; saying "the previous month"
     * would name a window the comparison does not use. An earlier draft of this method had four
     * branches that all produced this same string, which only disguised that fact.
     */
    public function baselineLabel(): string
    {
        return __('the :count days before that', ['count' => number_format($this->days())]);
    }

    /**
     * What was adjusted and why, or null when the period is exactly what was asked for.
     *
     * A clamp a merchant cannot see is a figure whose label does not describe it, so this is
     * rendered on the screen and carried into the CSV rather than kept for a log.
     */
    public function clampNote(): ?string
    {
        return $this->clampNote;
    }

    /** True when nothing was chosen, so the screen can mark the default without re-deriving it. */
    public function isDefault(): bool
    {
        return $this->key === self::DEFAULT;
    }

    /**
     * The presets the screen offers, as key => the words for that option.
     *
     * Built from `PRESETS` so an option cannot appear in the UI without being accepted by
     * `fromRequest()`, and cannot be accepted without appearing -- the drift that makes a filter
     * look broken to a merchant and fine to a developer.
     *
     * @return array<string, string>
     */
    public static function options(?CarbonInterface $now = null): array
    {
        $now = $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);

        $options = [];

        foreach (array_keys(self::PRESETS) as $key) {
            [$from, $to] = self::windowFor($key, $now);
            $options[$key] = (new self($key, $from, $to, null))->label();
        }

        $options[self::CUSTOM] = __('a period I choose');

        return $options;
    }

    /** Every accepted `ss_period` value, including `custom`. Used by the saved-view sanitiser. */
    public static function acceptedKeys(): array
    {
        return array_merge(array_keys(self::PRESETS), [self::CUSTOM]);
    }
}
