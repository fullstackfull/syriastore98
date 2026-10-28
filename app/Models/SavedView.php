<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * P2-04-9 / WS E3 — a merchant employee's named filter set for one list screen.
 *
 * THE OWNERSHIP IS `(store_id, created_by)` TOGETHER AND NEVER EITHER ALONE.
 *   - `created_by` is the EMPLOYEE's own id, deliberately NOT `creatorId()`: that helper returns
 *     the OWNER's id for staff, so using it would collapse every employee of a merchant onto one
 *     set of views — the opposite of the acceptance, and the same mistake `SettingOwnership` makes
 *     that ruled the `settings` table out as a home for this.
 *   - `store_id` is the tenant, so a merchant who switches store does not carry views across.
 *
 * `$fillable` IS DELIBERATELY EMPTY-OF-OWNERSHIP: `store_id` and `created_by` are NOT fillable, so
 * no mass assignment — from a request or anywhere else — can create a view owned by somebody else.
 * The controller sets both as properties, from the authenticated principal. This is the lesson
 * `order_notes.created_by` taught two slices ago, applied before rather than after.
 */
class SavedView extends Model
{
    /**
     * NOTE: `store_id` and `created_by` are absent ON PURPOSE. See the class docblock — they are
     * the ownership, and ownership that can be mass-assigned is not ownership.
     */
    protected $fillable = [
        'screen',
        'name',
        'filters',
    ];

    protected $casts = [
        'filters' => 'array',
    ];

    /**
     * The filters this view really applies, with anything outside the screen's vocabulary dropped.
     *
     * VALIDATED ON READ AS WELL AS ON WRITE, which is not belt-and-braces: the row outlives the
     * code that wrote it. A key removed from a screen's vocabulary later — or a row written by an
     * older version, or by hand — must not be able to inject a parameter into a query now. The
     * single source of truth is `TableFilterMemory::vocabularyFor()`, so there is no second list
     * to drift.
     *
     * @return array<string, string>
     */
    public function appliedFilters(): array
    {
        return self::sanitiseFilters($this->screen, is_array($this->filters) ? $this->filters : []);
    }

    /**
     * @param  array<array-key, mixed>  $filters
     * @return array<string, string>
     */
    public static function sanitiseFilters(?string $screen, array $filters): array
    {
        $allowed = \App\Services\TableFilterMemory::vocabularyFor((string) $screen);

        if ($allowed === []) {
            return [];
        }

        $clean = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $value = $filters[$key];

            // Scalars only. An array value reaching a query builder is how a filter becomes a
            // crash or worse, and `OrderDataTable` already defends against it on the request side.
            if (is_array($value) || is_object($value) || is_bool($value)) {
                continue;
            }

            $value = trim((string) $value);

            // An empty value is "Any", which is the absence of a filter rather than a filter.
            if ($value === '') {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /** The views the signed-in employee may see on one screen, in their own store. */
    public static function forCurrentPrincipal(string $screen)
    {
        $storeId = getCurrentStore();

        if (empty($storeId) || ! Auth::check()) {
            // F149's lesson: `where('store_id', null)` is `WHERE store_id IS NULL`. Fail closed.
            return self::query()->whereRaw('1 = 0');
        }

        return self::query()
            ->where('store_id', $storeId)
            ->where('created_by', Auth::id())
            ->where('screen', $screen)
            ->orderBy('name');
    }
}
