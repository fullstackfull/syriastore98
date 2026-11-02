<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Token security, stage 1: the read-only inventory that stage 3 is supposed to be chosen against.
 *
 * WHY THIS EXISTS AT ALL. `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` §Stage 1: *"Nothing today
 * can answer 'how old is the oldest token still in use?'"* — and the owner's decision (Decision
 * Register row 13) approves the token work staged, observability first. An expiry chosen without
 * this number is a guess that logs people out, and the proposal's own §"What would break if a
 * global expiration were switched on today" is the list of ways that goes wrong.
 *
 * IT MEASURES NOTHING HERE. This container has no production tokens; the command is the
 * instrument, and the figure it is for lives in the production database. Running it in a test or
 * a dev environment tells you about the fixtures, not about the fleet.
 *
 * THREE THINGS IT DELIBERATELY DOES.
 *
 *   1. SEPARATES "oldest token" from "oldest token STILL IN USE". A 400-day token nobody presents
 *      tells you nothing about who an expiry would log out. `last_used_at` is written on every
 *      authenticated request by `CustomAuth`, so the distinction is measurable, and conflating the
 *      two is the one way this report could be confidently wrong.
 *
 *   2. REPORTS THE GATE PER PRINCIPAL. More than the proposal asked for, and what the approved
 *      stage 3 needs: the expiry is for **merchant tokens only**, so one global figure would be
 *      decided by whichever shopper holds the oldest token and would answer the wrong question.
 *
 *   3. PRINTS NEITHER THE TOKEN NOR THE PRINCIPAL. `personal_access_tokens.token` is a SHA-256 of
 *      a live credential and `tokenable_id` identifies a person. Aggregates only — a report that
 *      leaks either turns an operational tool into the disclosure S4 is about.
 *
 * DAY ARITHMETIC, NOT DATE THRESHOLDS. Buckets are computed as `intdiv(seconds, 86400)` per row
 * rather than by comparing `created_at` against `now()->subDays(n)`. A threshold comparison puts a
 * token created exactly n days ago on whichever side of the edge the clock has drifted to between
 * the write and the read, which makes the boundary cases of this report unassertable — and a
 * bucket report with a wrong edge is worse than none, because it reads as a measurement.
 *
 * Chunked, so the table's size is not a reason not to run it.
 */
class TokenInventoryReport extends Command
{
    protected $signature = 'tokens:inventory';

    protected $description = 'Read-only age and usage inventory of personal access tokens (token security stage 1)';

    /** The bucket edges, in days, shared by the age and the recency tables. */
    private const EDGES = [7, 30, 90, 365];

    /**
     * The expiry the owner approved, in days — Decision Register row 13: *"30-day expiry for NEW
     * merchant tokens only"*. Not a parameter: the report exists to be read against the decision
     * that has been taken, and a configurable number here would invite reading it against one that
     * has not.
     */
    private const APPROVED_EXPIRY_DAYS = 30;

    /**
     * Rows read per page.
     *
     * A property rather than a literal so a test can shrink it and drive the paging itself. The
     * paging is not incidental here: see chunkById() below.
     */
    private int $pageSize = 1000;

    public function handle(): int
    {
        $now = now();

        $total = 0;
        $neverUsed = 0;
        $withExpiry = 0;
        $byPrincipal = [];
        $age = $this->emptyBuckets();
        $recency = $this->emptyBuckets();
        $oldestInUse = [];
        $dormancyOfOldest = [];
        $inUseOverApproved = [];
        $inUse = [];

        DB::table('personal_access_tokens')
            ->select(['id', 'tokenable_type', 'created_at', 'last_used_at', 'expires_at'])
            ->chunkById($this->pageSize, function ($rows) use (
                $now, &$total, &$neverUsed, &$withExpiry, &$byPrincipal, &$age, &$recency,
                &$oldestInUse, &$dormancyOfOldest, &$inUseOverApproved, &$inUse
            ) {
                foreach ($rows as $row) {
                    $total++;

                    $type = (string) ($row->tokenable_type ?? '(none)');
                    $byPrincipal[$type] = ($byPrincipal[$type] ?? 0) + 1;

                    if ($row->expires_at !== null) {
                        $withExpiry++;
                    }

                    $ageDays = $this->daysBetween($row->created_at, $now);
                    if ($ageDays !== null) {
                        $age[$this->bucketFor($ageDays)]++;
                    }

                    if ($row->last_used_at === null) {
                        $neverUsed++;

                        continue;
                    }

                    $usedDays = $this->daysBetween($row->last_used_at, $now);
                    if ($usedDays !== null) {
                        $recency[$this->bucketFor($usedDays)]++;
                    }

                    $inUse[$type] = ($inUse[$type] ?? 0) + 1;

                    /*
                     * THE GATE. Only a token somebody has presented counts, and the age reported is
                     * the token's OWN age, not how long ago it was used: the question stage 3 asks
                     * is "would the approved expiry have killed a token that is still working".
                     *
                     * ITS DORMANCY IS CARRIED WITH IT, because the figure is an UPPER BOUND set by
                     * a single token and a review was right that "has ever been presented" is not
                     * "is still in use": a token last seen 400 days ago is as unheld as one never
                     * used, and an ORPHAN -- its tokenable row deleted, so `CustomAuth` refuses it
                     * -- can never be presented again yet still pins the maximum. Both inflate the
                     * figure, which is the conservative direction, but a reader cannot tell an
                     * inflated bound from a live one without seeing how long ago it was last seen.
                     */
                    if ($ageDays !== null && $ageDays > ($oldestInUse[$type] ?? -1)) {
                        $oldestInUse[$type] = $ageDays;
                        $dormancyOfOldest[$type] = $usedDays;
                    }

                    /*
                     * AND THE COUNT, which is the figure the rollout actually turns on. The gate is
                     * a maximum with no cardinality, so it cannot answer "how many merchants would
                     * the approved expiry interrupt" -- and the age distribution above is over ALL
                     * tokens, never-used ones included, so no percentile can be read off it either.
                     */
                    if ($ageDays !== null && $ageDays > self::APPROVED_EXPIRY_DAYS) {
                        $inUseOverApproved[$type] = ($inUseOverApproved[$type] ?? 0) + 1;
                    }
                }
            });

        ksort($byPrincipal);
        ksort($oldestInUse);

        $this->line('TOKEN INVENTORY  (personal_access_tokens)');
        $this->line(str_repeat('-', 62));
        $this->row('total tokens', $total);
        $this->row('never used', $neverUsed);
        $this->row('with an expiry set', $withExpiry);
        $this->row('with no expiry', $total - $withExpiry);

        $this->line('');
        $this->line('by principal');
        foreach ($byPrincipal as $type => $count) {
            $this->row($type, $count);
        }
        if ($byPrincipal === []) {
            $this->row('(no tokens)', 0);
        }

        $this->line('');
        $this->line('age of token (created_at)');
        foreach ($age as $label => $count) {
            $this->row($label, $count);
        }

        $this->line('');
        $this->line('last used (last_used_at)');
        foreach ($recency as $label => $count) {
            $this->row($label . ' ago', $count);
        }

        $this->line('');
        $this->line('DECISION GATE -- oldest in use, per principal');
        foreach ($oldestInUse as $type => $days) {
            $dormant = $dormancyOfOldest[$type] ?? null;
            $this->row(
                $type,
                $days . ' days' . ($dormant === null
                    ? ''
                    : '  (last seen ' . $dormant . ' ' . ($dormant === 1 ? 'day' : 'days') . ' ago)')
            );
        }
        if ($oldestInUse === []) {
            $this->row('(no token is in use)', '-');
        }

        $this->line('');
        $this->line('  in use and older than ' . self::APPROVED_EXPIRY_DAYS . ' days, per principal');
        foreach ($byPrincipal as $type => $ignored) {
            $this->row($type, ($inUseOverApproved[$type] ?? 0) . ' of ' . ($inUse[$type] ?? 0) . ' in use');
        }
        if ($byPrincipal === []) {
            $this->row('(no tokens)', '-');
        }

        $this->line('');
        $this->line('  READ THE TWO TOGETHER. The gate is an UPPER BOUND set by one token, and it');
        $this->line('  counts a token that has EVER been presented -- so a dormant or orphaned');
        $this->line('  token inflates it. The count below it is the number the rollout turns on:');
        $this->line('  how many tokens a ' . self::APPROVED_EXPIRY_DAYS . '-day expiry would have refused.');
        $this->line('  Tokens never used are excluded from both: nobody is holding them.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function emptyBuckets(): array
    {
        $buckets = [];
        $from = 0;

        foreach (self::EDGES as $edge) {
            $buckets[$from . '-' . $edge . ' days'] = 0;
            $from = $edge + 1;
        }

        $buckets['over ' . self::EDGES[array_key_last(self::EDGES)] . ' days'] = 0;

        return $buckets;
    }

    private function bucketFor(int $days): string
    {
        $from = 0;

        foreach (self::EDGES as $edge) {
            if ($days <= $edge) {
                return $from . '-' . $edge . ' days';
            }
            $from = $edge + 1;
        }

        return 'over ' . self::EDGES[array_key_last(self::EDGES)] . ' days';
    }

    /**
     * Whole days between a stored timestamp and now, floored.
     *
     * Returns null for a value that cannot be parsed rather than guessing: a malformed timestamp
     * must not quietly land in the "0-7 days" bucket and read as a fresh token.
     */
    private function daysBetween(mixed $stored, \Illuminate\Support\Carbon $now): ?int
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            $at = \Illuminate\Support\Carbon::parse((string) $stored);
        } catch (\Throwable) {
            return null;
        }

        return intdiv(max(0, $now->getTimestamp() - $at->getTimestamp()), 86400);
    }

    private function row(string $label, int|string $value): void
    {
        $this->line(sprintf('  %-34s %s', $label, $value));
    }
}
