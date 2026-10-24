<?php

namespace Midtrans;

/**
 * The Midtrans SDK's own HTTP stub switch, supplied here because the SDK's copy lives in its
 * `tests/` directory, which a production-style `composer install` does not have to ship.
 *
 * `Midtrans\ApiRequestor::remoteCall()` checks `class_exists('\Midtrans\MT_Tests') &&
 * MT_Tests::$stubHttp` and, when set, returns `$stubHttpResponse` instead of calling Midtrans. The
 * switch is OFF unless a test turns it on, so defining this class changes nothing for any other
 * test or for production (nothing under `tests/` is autoloaded outside the test run).
 *
 * @internal
 */
class MT_Tests
{
    public static $stubHttp = false;

    public static $stubHttpResponse;

    public static $stubHttpStatus;

    public static $lastHttpRequest;

    public static function reset(): void
    {
        self::$stubHttp = false;
        self::$stubHttpResponse = null;
        self::$lastHttpRequest = null;
    }
}
