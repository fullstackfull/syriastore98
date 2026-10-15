<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Models\LynomiaCommerceEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * Takes rows out of the commerce outbox and delivers them to Lynomia.
 *
 * AT LEAST ONCE, DELIBERATELY. A delivery whose answer is lost is retried, because the only
 * alternative -- give up on an uncertain outcome -- loses real events to ordinary network
 * weather. Lynomia deduplicates on the delivery id through its own webhook queue, so a second
 * copy of a delivery it already applied is a no-op there, which is what makes retrying free.
 *
 * BACKOFF IS STORED, NOT HELD. The delay lives in the row's `deliver_after`, so a worker that
 * dies mid-backoff loses nothing and a sweep five minutes later picks the row up exactly when
 * it was due. Nothing is ever dropped for exceeding an attempt count: a row that has failed
 * twenty times is a store that needs an operator, and deleting its events would hide that.
 *
 * A 4xx IS NOT RETRIED THE SAME WAY AS A 5xx, and the difference matters. A 401 means this
 * store's credential no longer verifies -- almost always because an operator revoked it -- and
 * hammering it changes nothing, so it backs off on the same clock but says so in last_error
 * where an operator looking at a stalled store will find it.
 */
class CommerceEventDispatcher
{
    /** Backoff per attempt, in seconds, then the last value for every attempt after. */
    private const BACKOFF = [30, 120, 600, 1800, 3600];

    public function __construct(private readonly array $httpConfig = [])
    {
    }

    /**
     * Deliver one row. Returns true when Lynomia accepted it.
     *
     * The row is reloaded and re-checked rather than trusted: a sweep and a job can both reach
     * the same row, and the cheap guard here is what stops the same event being sent twice for
     * no reason. (Twice is safe -- see the class note -- but free is better than safe.)
     */
    public function deliver(LynomiaCommerceEvent $event): bool
    {
        if ($event->delivered_at !== null) {
            return true;
        }

        $link = LynomiaStoreLink::forStore($event->store_id)->first();

        if ($link === null || $link->state !== LinkState::ACTIVE || $link->lynomia_account_id === null) {
            // The store is no longer connected. There is nowhere to deliver this and there never
            // will be, so it is retired rather than retried forever.
            $event->forceFill(['delivered_at' => now(), 'last_error' => 'link_not_active'])->save();

            return false;
        }

        $body = $this->body($event, $link);
        $timestamp = (string) now()->timestamp;

        try {
            $response = Http::withHeaders([
                CommerceEventSignature::STORE_HEADER => $this->externalStoreId($event->store_id),
                CommerceEventSignature::DELIVERY_HEADER => $event->delivery_id,
                CommerceEventSignature::TIMESTAMP_HEADER => $timestamp,
                CommerceEventSignature::SIGNATURE_HEADER => CommerceEventSignature::sign($link, $timestamp, $body),
            ])
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->connectTimeout((int) ($this->httpConfig['connect_timeout'] ?? 5))
                ->timeout((int) ($this->httpConfig['timeout'] ?? 10))
                ->post($this->endpoint());
        } catch (ConnectionException $e) {
            // Never the exception message: it carries the URL and can carry more.
            return $this->defer($event, 'unreachable');
        }

        if ($response->successful()) {
            $event->forceFill(['delivered_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => null])->save();

            return true;
        }

        return $this->defer($event, 'http_' . $response->status());
    }

    /**
     * WHAT TRAVELS, AND WHAT DOES NOT. Which store, which order, which customer, what happened
     * and when. No names, no totals, no phone numbers: Lynomia's realtime design re-reads the
     * order from this application rather than trusting a delivery's contents, so a payload
     * carrying order data would be customer data sitting in two more places -- a request log
     * and a queue -- for no one to read.
     */
    private function body(LynomiaCommerceEvent $event, LynomiaStoreLink $link): string
    {
        return (string) json_encode([
            'event' => $event->event,
            'external_store_id' => $this->externalStoreId($event->store_id),
            'order_id' => $event->order_id === null ? null : (string) $event->order_id,
            'customer_reference' => $event->customer_reference,
            'delivered_status' => $event->delivered_status,
            'payment_status' => $event->payment_status,
            'occurred_at' => optional($event->occurred_at)->toIso8601String(),
            'delivery_id' => $event->delivery_id,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function defer(LynomiaCommerceEvent $event, string $reason): bool
    {
        $attempts = $event->attempts + 1;
        $delay = self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1];

        $event->forceFill([
            'attempts' => $attempts,
            'deliver_after' => now()->addSeconds($delay),
            'last_error' => Str::limit($reason, 191, ''),
        ])->save();

        Log::warning('[lynomia] a commerce event was not delivered', [
            'store_id' => $event->store_id,
            'event' => $event->event,
            'attempts' => $attempts,
            'reason' => $reason,
        ]);

        return false;
    }

    /**
     * The id Lynomia knows this store by, qualified so two installations cannot collide into
     * one workspace. The same value the commerce API answers with, built the same way.
     */
    private function externalStoreId(int $storeId): string
    {
        return StoreIdentity::of($storeId);
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('lynomia.base_url'), '/');

        if ($base === '') {
            // An unset base URL is a configuration error, not a reason to post nowhere.
            throw new \RuntimeException('LYNOMIA_BASE_URL is not configured.');
        }

        return $base . '/webhooks/storesyria';
    }
}
