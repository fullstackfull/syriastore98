<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ANA-003 — one standing request for an analytics export: this period, this often, to this address.
 *
 * NOT `Cachable`, and the reason is the same shape as the nudge ledger's. CLAUDE.md records that 79
 * of the 95 models are `Cachable` and that a stale read has produced three measured false readings.
 * The runner's whole correctness rests on reading `next_due_at` as it is RIGHT NOW and winning a
 * conditional write against it; a cached answer to "is this due" is a report sent twice or not at
 * all.
 *
 * FIVE COLUMNS ARE NOT FILLABLE AND EACH IS A CONTROL, not an oversight:
 *
 *   - `store_id` and `created_by` are the ownership -- the lesson `order_notes.created_by` taught.
 *   - `recipient_email` is the SECURITY one. A fillable recipient makes this an authenticated mail
 *     relay: a merchant could schedule their store's figures to any address on the internet. It is
 *     written from the authenticated user's own account address and re-checked on every run.
 *   - `next_due_at` is the claim the runner competes for; mass-assigning it would let a caller
 *     trigger or suppress a send.
 *   - `last_run_at` / `last_outcome` are the record of what happened, and a writable record is not
 *     a record.
 */
class AnalyticsReportSchedule extends Model
{
    protected $table = 'analytics_report_schedules';

    /** @see the class docblock for the columns deliberately absent. */
    protected $fillable = [
        'name',
        'period_key',
        'period_from',
        'period_to',
        'frequency',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'next_due_at' => 'datetime',
        'last_run_at' => 'datetime',
        'period_from' => 'date',
        'period_to' => 'date',
    ];

    public const FREQ_DAILY = 'daily';

    public const FREQ_WEEKLY = 'weekly';

    public const FREQ_MONTHLY = 'monthly';

    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_SKIPPED_NO_STORE = 'skipped_no_store';

    public const OUTCOME_SKIPPED_NO_OWNER = 'skipped_no_owner';

    /**
     * The cadences a merchant may choose, as key => the words for it.
     *
     * THESE ARE PREFERENCES, NOT A MARKETING POLICY. A merchant deciding how often to receive their
     * OWN figures is unlike GAP-219's shopper cadence, which is the owner's decision and is
     * deliberately left unset -- nobody is being messaged but the person who asked.
     *
     * @return array<string, string>
     */
    public static function frequencies(): array
    {
        return [
            self::FREQ_DAILY => __('every day'),
            self::FREQ_WEEKLY => __('every week'),
            self::FREQ_MONTHLY => __('every month'),
        ];
    }

    /**
     * What each recorded outcome MEANS, as key => the words for it.
     *
     * The list used to print the key itself -- `skipped_no_owner` -- on a merchant's screen, in
     * either language. An outcome is recorded so that the merchant can tell WHICH of the things
     * that can go wrong did; an identifier tells them that something has a name.
     *
     * The sentences follow `ScheduledReportRunner::send()`, which is the only writer: "no owner"
     * is one outcome with three causes there (the account is gone, it has no address, it may no
     * longer read reports), so the sentence names all three rather than guessing which.
     *
     * `$locale` exists for the test that holds every outcome to having words in both languages.
     *
     * @return array<string, string>
     */
    public static function outcomes(?string $locale = null): array
    {
        return [
            self::OUTCOME_SENT => __('The file was sent.', [], $locale),
            self::OUTCOME_FAILED => __('The last attempt failed: the file could not be built or sent.', [], $locale),
            self::OUTCOME_SKIPPED_NO_STORE => __('Skipped: the store this was scheduled for no longer exists.', [], $locale),
            self::OUTCOME_SKIPPED_NO_OWNER => __('Skipped: the person who scheduled it can no longer receive it. Their account is gone, has no e-mail address, or may no longer read reports.', [], $locale),
        ];
    }

    /**
     * The schedules the signed-in employee may see, in their own store. FAILS CLOSED.
     *
     * `where('store_id', null)` is `WHERE store_id IS NULL`, which would match nothing here but is
     * the shape that has bitten before (F149), so the guard is explicit rather than incidental.
     * `created_by` is `Auth::id()` for the same reason `SavedView` uses it: a schedule is one
     * employee's standing request, and two employees of one merchant must not share them.
     */
    public static function forCurrentPrincipal()
    {
        $storeId = getCurrentStore();

        if (empty($storeId) || ! Auth::check()) {
            return self::query()->whereRaw('1 = 0');
        }

        return self::query()
            ->where('store_id', $storeId)
            ->where('created_by', Auth::id())
            ->orderBy('name');
    }

    /** Has this schedule ever actually produced a file? Used to show "never run" honestly. */
    public function hasEverRun(): bool
    {
        return $this->last_run_at !== null;
    }
}
