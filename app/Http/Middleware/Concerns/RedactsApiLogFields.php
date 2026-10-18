<?php

namespace App\Http\Middleware\Concerns;

/**
 * Credential redaction for the two API request loggers.
 *
 * WHY THIS IS SHARED AND NOT COPIED. `APILog` logged the whole Request object and the whole
 * response body on the storefront auth routes, so `storage/logs/api-*.log` held every submitted
 * password, every password-reset OTP and every Sanctum plainTextToken in plaintext. The channel
 * is `daily` with 15-day retention (config/logging.php:134-139), so a reader of the log could
 * complete somebody's password reset, or simply reuse their bearer token, for up to a fortnight
 * after the fact. That was fixed in APILog during the password-recovery emergency.
 *
 * `AdminApiLog` -- 128 route declarations in routes/api.php, writing to THE SAME CHANNEL -- was
 * left with the original body. Among those routes:
 *
 *     routes/api.php:134  adminlogin                     the operator and merchant login
 *     routes/api.php:304  updatepassword                 an administrator's own password change
 *     routes/api.php:363  deliveryboysReset-password/{id} a driver's password, set by an admin
 *
 * So the same leak continued on the HIGHER-privilege surface: administrator passwords, driver
 * passwords and admin bearer tokens on disk in plaintext. Fixing one logger and not its twin is
 * how that happened, so the logic now lives in one place and both use it.
 *
 * The URL, method, status and non-sensitive body are still logged, so the diagnostic value of
 * the log is unchanged -- only the credentials are gone.
 */
trait RedactsApiLogFields
{
    /**
     * Field names whose VALUES must never reach the log. Matched as a case-insensitive SUBSTRING
     * of the key, so `new_password_confirmation` and `customer_otp` are covered without being
     * listed.
     */
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password', 'old_password',
        'otp', 'password_otp', 'reset_token', 'token', 'plainTextToken', 'access_token',
        'api_key', 'secret', 'authorization',
    ];

    protected function shouldRedact(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::REDACT as $sensitive) {
            if (str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    protected function scrub($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->shouldRedact($key)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            $clean[$key] = is_array($item) ? $this->scrub($item) : $item;
        }

        return $clean;
    }

    /**
     * The response body, scrubbed, or a shape-only placeholder when it is not JSON and individual
     * fields therefore cannot be located.
     */
    protected function scrubbedResponseBody($response): ?string
    {
        if (! is_object($response) || ! method_exists($response, 'getContent')) {
            return null;
        }

        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);

        return is_array($decoded)
            ? json_encode($this->scrub($decoded))
            : '[non-JSON response, ' . strlen($content) . ' bytes]';
    }
}
