<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * F190 / OWNER DECISION D-29.5 — the per-field upload rule reader.
 *
 * `Utility::upload_file()` REPLACES its whole rule array when `$custom_validation` is non-empty
 * (`app/Models/Utility.php:376-385`):
 *
 *     if (count($custom_validation) > 0) { $validation = $custom_validation; }
 *     else { $validation = ['mimes:' . $mimes, 'max:' . $max_size]; }
 *
 * — there is no merge. So a caller that passes a list WITHOUT `max:` does not narrow the accepted
 * types while keeping the size cap; it narrows the types and SILENTLY REMOVES THE SIZE CAP, after
 * which the only ceiling left is PHP's own `upload_max_filesize`, and the validator reports nothing
 * because nothing asked it to. That is the exact failure the decision names: "include max with any
 * custom_validation; do not drop the size rule when replacing the Rule Array."
 *
 * Prose cannot hold that, so this class is the single place the lists are read and the rule is
 * enforced mechanically: A LIST WITHOUT `max:` IS REFUSED, not applied. Refusing returns `[]`,
 * which makes `upload_file()` take its own branch — the platform-wide list, WHICH ALWAYS CARRIES A
 * `max:`. So a misconfiguration costs the type narrowing (today's behaviour, no regression) and
 * never the size cap. Failing the other way would be the defect itself.
 *
 * `enabled => false` returns `[]` by the same route, and that is how the owner's F217 gate on the
 * digital list is held: see `config/uploads.php` for which half is enabled and why.
 */
class UploadRules
{
    /**
     * The rules for one upload field, or `[]` to leave the platform-wide list in force.
     */
    public static function for(string $field): array
    {
        $config = config('uploads.' . $field);

        if (! is_array($config) || empty($config['enabled'])) {
            return [];
        }

        $rules = $config['rules'] ?? [];

        if (! is_array($rules) || $rules === []) {
            return [];
        }

        /*
         * A `mimes:` RULE WITHOUT AN `extensions:` RULE IS REFUSED THE SAME WAY, and for the same
         * mechanical reason the size rule is: prose did not hold it. `mimes:` validates the bytes
         * and `extensions:` validates the name, and the web server acts on the NAME — a valid MP4
         * called `clip.html` passed the content check and would have been stored under that
         * extension inside the document root. Measured; see config/uploads.php.
         */
        if (self::names($rules, 'mimes:') && ! self::names($rules, 'extensions:')) {
            Log::warning('uploads.' . $field . ' declares mimes: without extensions:; not applied, because the stored filename keeps the caller\'s extension and the web server acts on the name, not the bytes.');

            return [];
        }

        if (! self::carriesASizeRule($rules)) {
            /*
             * Not applied. A caller reading this log is looking at a configuration that would have
             * removed the size cap, which is why it is at warning rather than debug.
             */
            Log::warning('uploads.' . $field . ' declares rules without a max: rule; not applied, because applying it would remove the size cap (Utility::upload_file replaces the rule array rather than merging).');

            return [];
        }

        return array_values($rules);
    }

    private static function carriesASizeRule(array $rules): bool
    {
        return self::names($rules, 'max:');
    }

    /** Does the list carry a rule with this prefix? */
    private static function names(array $rules, string $prefix): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
