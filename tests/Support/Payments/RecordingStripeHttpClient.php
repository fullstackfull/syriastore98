<?php

namespace Tests\Support\Payments;

use Stripe\HttpClient\ClientInterface;

/**
 * A Stripe HTTP client that records requests and answers from a canned script, so a test can prove
 * EXACTLY what reached Stripe without any network call.
 *
 * WHY THIS EXISTS. `\Stripe\PaymentIntent::create()` is a static call, so there is no constructor
 * to inject and no facade to fake. But the SDK resolves its transport through
 * `\Stripe\ApiRequestor::setHttpClient()` (vendor/stripe/stripe-php/lib/ApiRequestor.php:588), and
 * the contract is small: `request($method, $absUrl, $headers, $params, $hasFile)` returning
 * `[rawBody, statusCode, headers]` (lib/HttpClient/ClientInterface.php:21). Installing a fake there
 * is the narrowest seam that makes the amount assertable.
 *
 * This matters because the question "is the amount server-authoritative" cannot be answered by a
 * refusal test. A refusal proves only that an unauthorized caller was stopped; it says nothing
 * about what number an AUTHORIZED call puts on the merchant's Stripe account. Without this, the
 * only available assertion would be "the handler returned an error because no Stripe secret is
 * configured" -- which passes whether the amount is right or wrong.
 *
 * NOTHING LEAVES THE PROCESS. There is no socket, no curl handle and no API key use, so this
 * satisfies "do not make live charges or contact production payment services" by construction
 * rather than by configuration.
 */
class RecordingStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, params: array, headers: array}> */
    public array $requests = [];

    /** @var callable|null */
    private $responder;

    public function __construct(?callable $responder = null)
    {
        $this->responder = $responder;
    }

    /**
     * Install this client and return it. The caller is responsible for restoring the real one;
     * StripeTestsFakeTransport::restore() does that in tearDown.
     */
    public static function install(?callable $responder = null): self
    {
        $client = new self($responder);
        \Stripe\ApiRequestor::setHttpClient($client);

        return $client;
    }

    public static function restore(): void
    {
        // Passing null makes ApiRequestor lazily rebuild its default CurlClient on next use, so a
        // later test that genuinely wants the real transport is not left holding this fake.
        \Stripe\ApiRequestor::setHttpClient(null);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'params' => $params,
            // Headers are recorded because the idempotency key travels as `Idempotency-Key`, not
            // as a parameter -- so a test that only inspected $params could not see it.
            'headers' => $headers,
        ];

        if ($this->responder !== null) {
            return ($this->responder)($method, $absUrl, $params);
        }

        return [
            json_encode([
                'id' => 'pi_fake_' . count($this->requests),
                'object' => 'payment_intent',
                'client_secret' => 'pi_fake_secret_' . count($this->requests),
                'amount' => $params['amount'] ?? 0,
                'currency' => $params['currency'] ?? 'usd',
                'status' => 'requires_payment_method',
            ]),
            200,
            [],
        ];
    }

    /** The last PaymentIntent create call, or null if none was made. */
    public function lastPaymentIntentCreate(): ?array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request['method'] === 'post' && str_contains($request['url'], '/v1/payment_intents')) {
                return $request;
            }
        }

        return null;
    }

    /** The `Idempotency-Key` header value of the last PaymentIntent create, or null. */
    public function lastIdempotencyKey(): ?string
    {
        $create = $this->lastPaymentIntentCreate();

        foreach ($create['headers'] ?? [] as $header) {
            if (stripos((string) $header, 'Idempotency-Key:') === 0) {
                return trim(substr((string) $header, strlen('Idempotency-Key:')));
            }
        }

        return null;
    }

    public function createCount(): int
    {
        $count = 0;
        foreach ($this->requests as $request) {
            if ($request['method'] === 'post' && str_contains($request['url'], '/v1/payment_intents')) {
                $count++;
            }
        }

        return $count;
    }
}
