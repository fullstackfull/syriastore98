<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use App\Http\Middleware\Concerns\RedactsApiLogFields;

class APILog
{
    /**
     * The redaction lives in the trait so AdminApiLog -- 128 routes on the same log channel,
     * including adminlogin -- cannot drift away from it again. See the trait for what leaked.
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
