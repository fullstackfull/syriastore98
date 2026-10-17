<?php

namespace Workdo\LynomiaChat\app\Services;

/**
 * The key Lynomia signs a store's deliveries with, and the check that a delivery really is one.
 *
 * THE KEY IS DERIVED, NEVER STORED. Lynomia generates a webhook secret by default and hands it
 * back on creation -- and keeping that copy would mean a live signing key sitting in
 * lynomia_store_links, in a database whose APP_KEY is published and unrotated. config/lynomia.php
 * has refused to put a secret in this database since Phase 1 for exactly that reason, and a
 * signing key is a secret by that standard.
 *
 * So Store Syria chooses the secret instead of accepting one: it is HMAC(master, "lynomia-webhook:{store}")
 * over a master key read from the environment on each use. Nothing is persisted, every store has
 * a different key -- so one leaked secret forges deliveries for one store, not all of them -- and
 * the key is recomputed on the way in rather than looked up.
 *
 * THE SIGNATURE FORMAT IS LYNOMIA'S, NOT AN INVENTION. lib/webhooks/trigger.rb sends
 * X-Chatwoot-Signature: sha256=HMAC_SHA256(secret, "{timestamp}.{body}") alongside
 * X-Chatwoot-Timestamp. Signing the timestamp WITH the body is what makes it replay-resistant:
 * an attacker replaying yesterday's delivery cannot move the timestamp without invalidating the
 * signature, and cannot keep it without failing the freshness window.
 */
class EventSignature
{
    public const SIGNATURE_HEADER = 'X-Chatwoot-Signature';
    public const TIMESTAMP_HEADER = 'X-Chatwoot-Timestamp';
    public const DELIVERY_HEADER = 'X-Chatwoot-Delivery';

    /**
     * The scope of the delivery feed that is not about one merchant's store.
     *
     * Store Syria's own Lynomia workspace reports what became of the operational notices it
     * sends (Delivery::StatusFeed). Those messages belong to no store, so there is no store id
     * to derive a secret from -- and reusing a merchant's would mean a merchant who learned
     * their own secret could forge the platform's deliveries.
     */
    public const PLATFORM_SCOPE = 'platform';

    /** The secret for one store. Derived, so there is no copy of it anywhere. */
    public static function secretFor(int $storeId): string
    {
        return self::deriveFor((string) $storeId);
    }

    /** The secret for the installation's own workspace feed. A scope no store id can collide with. */
    public static function platformSecret(): string
    {
        return self::deriveFor(self::PLATFORM_SCOPE);
    }

    private static function deriveFor(string $scope): string
    {
        $master = (string) config('lynomia.webhook_master_secret');

        if ($master === '') {
            // An unconfigured master key must not quietly produce a predictable secret that
            // anybody could compute -- that would be worse than no verification, because the
            // signature check would still appear to pass.
            throw new \RuntimeException('LYNOMIA_WEBHOOK_MASTER_SECRET is not configured.');
        }

        return hash_hmac('sha256', "lynomia-webhook:{$scope}", $master);
    }

    /**
     * Is this body really a delivery for this store, and recent enough to act on?
     *
     * CONSTANT-TIME COMPARISON. A signature check that short-circuits on the first wrong byte
     * leaks, over enough attempts, how much of a guess was right.
     */
    public static function verify(int $storeId, string $body, ?string $signature, ?string $timestamp): bool
    {
        return self::verifyWith(self::secretFor($storeId), $body, $signature, $timestamp);
    }

    /** The same question for the platform feed, which has no store. */
    public static function verifyPlatform(string $body, ?string $signature, ?string $timestamp): bool
    {
        return self::verifyWith(self::platformSecret(), $body, $signature, $timestamp);
    }

    private static function verifyWith(string $secret, string $body, ?string $signature, ?string $timestamp): bool
    {
        if ($signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (! self::fresh((int) $timestamp)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * REPLAY PROTECTION, and why a window is needed at all. The signature proves the body and
     * timestamp were produced together by the key holder; it says nothing about WHEN they
     * arrived. Without a window, a delivery captured once could be re-sent indefinitely. The
     * window is deliberately generous relative to a webhook's normal latency -- a queue backlog
     * is ordinary and must not look like an attack -- and the delivery-id check behind it makes
     * a replay inside the window a no-op anyway.
     */
    private static function fresh(int $timestamp): bool
    {
        $tolerance = max(60, (int) config('lynomia.webhook_tolerance_seconds', 300));

        return abs(time() - $timestamp) <= $tolerance;
    }
}
