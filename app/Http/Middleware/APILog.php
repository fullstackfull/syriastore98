<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class APILog
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    /**
     * Field names whose VALUES must never reach the log. Matched case-insensitively against both
     * request input keys and response body keys.
     *
     * WHY THIS LIST EXISTS. `terminate()` logged the whole Request object and the whole response
     * body, and this middleware is on the storefront auth routes -- login, register and all three
     * password-recovery steps (routes/api.php:46-52). So `storage/logs/api.log` held, in
     * plaintext: every submitted password, every password-reset OTP, and every Sanctum
     * plainTextToken the responses hand back. The channel is `daily` with 15-day retention
     * (config/logging.php:134-139), so a reader of the log could complete somebody's password
     * reset, or simply reuse their bearer token, for up to a fortnight after the fact.
     *
     * The URL, method, status and the non-sensitive body are still logged, so the diagnostic
     * value of the log is unchanged -- only the credentials are gone.
     */
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password', 'old_password',
        'otp', 'password_otp', 'reset_token', 'token', 'plainTextToken', 'access_token',
        'api_key', 'secret', 'authorization',
    ];

    private function shouldRedact(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::REDACT as $sensitive) {
            if (str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $value */
    private function scrub($value)
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

    public function terminate($request, $response)
    {
        // The Authorization header is not part of $request->all(), and logging the Request object
        // included it. Only the scrubbed INPUT is logged now, never the raw object.
        $input = $this->scrub($request->all());

        $body = null;

        if (is_object($response) && method_exists($response, 'getContent')) {
            $decoded = json_decode((string) $response->getContent(), true);
            $body = is_array($decoded)
                ? json_encode($this->scrub($decoded))
                // Not JSON, so individual fields cannot be located. Logging it verbatim risks the
                // very leak this guards against, so only its shape is recorded.
                : '[non-JSON response, ' . strlen((string) $response->getContent()) . ' bytes]';
        }

        Log::channel('API_log')->info(' *********************************** API START *********************************** ');
        Log::channel('API_log')->info('API URL');
        Log::channel('API_log')->info($request->url());
        Log::channel('API_log')->info('request');
        Log::channel('API_log')->info(json_encode(['method' => $request->method(), 'input' => $input]));
        Log::channel('API_log')->info(PHP_EOL);
        Log::channel('API_log')->info('response');
        Log::channel('API_log')->info(json_encode([
            'status' => is_object($response) && method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
            'body' => $body,
        ]));
        Log::channel('API_log')->info(' *********************************** API END *********************************** ');
    }
}
