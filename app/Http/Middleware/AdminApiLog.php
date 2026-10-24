<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use App\Http\Middleware\Concerns\RedactsApiLogFields;

class AdminApiLog
{
    /**
     * THE SAME LEAK AS APILog, ON THE HIGHER-PRIVILEGE SURFACE, and it survived the
     * password-recovery emergency because only the storefront logger was looked at.
     *
     * terminate() logged `$request` -- the whole object, which stringifies to the complete HTTP
     * request including the Authorization header and the body -- and `$response` verbatim, to the
     * `API_log` channel (daily, 15-day retention). This middleware guards 128 route declarations
     * in routes/api.php, among them `adminlogin` (:134), `updatepassword` (:304) and
     * `deliveryboysReset-password/{id}` (:363). So administrator passwords, delivery-driver
     * passwords and admin bearer tokens were written to disk in plaintext and kept for a
     * fortnight.
     *
     * Found by the independent review of the password-recovery fix, under its brief to check
     * "relevant API request logging for exposure of OTPs, passwords or reset credentials".
     */
    use RedactsApiLogFields;

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

    public function terminate($request, $response)
    {
        // The Authorization header is not part of $request->all(), and logging the Request object
        // included it. Only the scrubbed INPUT is logged now, never the raw object.
        $input = $this->scrub($request->all());
        $body = $this->scrubbedResponseBody($response);

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
