<?php

namespace App\Libraries\PhonePe;

use Illuminate\Support\Facades\Http;
use Exception;

class PhonePe
{
    private string $clientId;
    private string $clientSecret;
    private array $urls;
    private ?string $accessToken = null;

    public function __construct(string $clientId, string $clientSecret, string $env = 'sandbox')
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->urls = $this->getUrls($env);
    }

    private function getUrls(string $env): array
    {
        return ($env === 'production' || $env === 'live') ? [
            'token' => 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token',
            'payment' => 'https://api.phonepe.com/apis/pg/checkout/v2/pay',
            'status' => 'https://api.phonepe.com/apis/pg/checkout/v2/order',
            'refund' => 'https://api.phonepe.com/apis/pg/payments/v2/refund'
        ] : [
            'token' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token',
            'payment' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/checkout/v2/pay',
            'status' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/checkout/v2/order',
            'refund' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/payments/v2/refund'
        ];
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        
        $response = Http::asForm()->post($this->urls['token'], [
            'client_id' => $this->clientId,
            'client_version' => '1',
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials'
        ]);

        $data = $response->json();
        $this->accessToken = $data['access_token'];
        
        return $this->accessToken;
    }

    public function initiatePayment(array $params): array
    {
        try {
            $token = $this->getAccessToken();
            $payload = [
                'merchantOrderId' => $params['merchantOrderId'],
                'amount' => (int) $params['amount'] * 100,
                'paymentFlow' => [
                    'type' => 'PG_CHECKOUT',
                    'message' => $params['message'] ?? 'Payment message',
                    'merchantUrls' => [
                        'redirectUrl' => $params['redirectUrl']
                    ]
                ]
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'O-Bearer ' . $token
            ])->post($this->urls['payment'], $payload);

            $data = $response->json();
            if ($response->successful() && isset($data['redirectUrl'])) {
                return ['status' => true, 'data' => $data['redirectUrl']];
            }

            return ['status' => false, 'error' => $data['message'] ?? 'Payment failed'];

        } catch (Exception $e) {
            return ['status' => false, 'error' => $e->getMessage()];
        }
    }

    public function checkStatus(string $merchantOrderId): array
    {
        try {
            $token = $this->getAccessToken();

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'O-Bearer ' . $token
            ])->get($this->urls['status'] . '/' . $merchantOrderId . '/status');

            return $response->json();

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function initiateRefund(array $params): array
    {
        try {
            $token = $this->getAccessToken();

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'O-Bearer ' . $token
            ])->post($this->urls['refund'], $params);

            return $response->json();

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}