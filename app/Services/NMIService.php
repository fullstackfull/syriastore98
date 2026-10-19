<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class NMIService
{
    /**
     * NMI's direct-post endpoint, as the controller hard-coded it before this method existed.
     *
     * Deliberately NOT read from config: `services.nmi.endpoint` is the PLAN-payment endpoint
     * this class's processPayment() uses with platform credentials, and silently switching the
     * storefront charge onto a different URL is not a refactor.
     */
    public const DIRECT_POST_ENDPOINT = 'https://secure.networkmerchants.com/api/transact.php';

    protected $client;
    protected $endpoint;
    protected $username;
    protected $password;

    public function __construct()
    {
        $this->client = new Client();
        $this->endpoint = config('services.nmi.endpoint');
        $this->username = config('services.nmi.username');
        $this->password = config('services.nmi.password');
    }

    /**
     * Post a `sale` to NMI's direct-post endpoint and return its parsed answer.
     *
     * WHY THIS MOVED HERE, P01-COMMERCE-SEC-02. NMIPayController::getNMIProductStatus built its
     * own `new Client()` inline and posted the card details from inside the controller. That is
     * the one line in the method that cannot be driven from a test, and it sits BEFORE every
     * guard the order writer below it needs -- so the whole writer was unreachable by any test,
     * which is exactly why the package before this one stopped at reporting it.
     *
     * Moving the call to the already-injected service changes no behaviour (same endpoint, same
     * form fields, same parse) and gives the suite a seam: bind a double for NMIService and the
     * order writer can be driven without a card number leaving the process. The seam proves the
     * WRITER's guards. It proves nothing about NMI, and no test may claim otherwise.
     *
     * The credentials stay the CALLER's: this endpoint authenticates with the store's own
     * `security_key`, which the controller reads per store, not with the platform-wide
     * username/password processPayment() uses.
     *
     * @param  array<string, mixed>  $formParams
     * @return array<string, mixed>  NMI's URL-encoded response, parsed. `response == 1` is
     *                               success; an empty array means the request itself failed.
     */
    public function chargeSale(array $formParams): array
    {
        try {
            $response = $this->client->post(self::DIRECT_POST_ENDPOINT, [
                'form_params' => $formParams,
            ]);

            parse_str($response->getBody()->getContents(), $parsed);

            return is_array($parsed) ? $parsed : [];
        } catch (RequestException $e) {
            /*
             * A transport failure is NOT a successful sale. Returning an empty array makes the
             * caller's `response == 1` test false, which is the fail-closed direction: no order
             * is written and no stock moves. The previous inline call let the exception escape as
             * a 500 mid-charge instead.
             */
            \Illuminate\Support\Facades\Log::error('NMI sale request failed before any answer was read.', [
                'reason' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function processPayment($data)
    {
        try {
            $response = $this->client->post($this->endpoint, [
                'form_params' => array_merge($data, [
                    'username' => $this->username,
                    'password' => $this->password,
                    'type' => 'sale'
                ]),
            ]);

            $responseBody = $response->getBody()->getContents();
            parse_str($responseBody, $parsedResponse); // Parse the URL-encoded response

            return $parsedResponse;
        } catch (RequestException $e) {
            // Return the error response
            if ($e->hasResponse()) {
                return json_decode($e->getResponse()->getBody()->getContents(), true);
            } else {
                return ['error' => 'Unable to process payment. No response from NMI.'];
            }
        }
    }
}
