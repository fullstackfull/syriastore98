<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliveryFirebaseNotificationService
{
    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = []
    ): array {
        if ($token === '' || $token === 'YOUR_FIREBASE_TOKEN') {
            throw new RuntimeException('Invalid Delivery Firebase token.');
        }

        $projectId = config('services.delivery_firebase.project_id');
        $credentialsPath = config('services.delivery_firebase.credentials');

        if (!$projectId) {
            throw new RuntimeException('DELIVERY_FIREBASE_PROJECT_ID is missing.');
        }

        $fullCredentialsPath = base_path($credentialsPath);

        if (!is_file($fullCredentialsPath)) {
            throw new RuntimeException(
                "Delivery Firebase credentials file not found: {$fullCredentialsPath}"
            );
        }

        $accessToken = $this->getAccessToken($fullCredentialsPath);

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $this->normalizeData($data),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                        'channel_id' => 'default',
                    ],
                ],
            ],
        ];

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(20)
            ->post(
                "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                $payload
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Delivery Firebase error: '.$response->status().' '.$response->body()
            );
        }

        return $response->json();
    }

    private function getAccessToken(string $credentialsPath): string
    {
        try {
            $credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'],
                $credentialsPath
            );

            $token = $credentials->fetchAuthToken();

            if (empty($token['access_token'])) {
                throw new RuntimeException('Could not generate Delivery Firebase access token.');
            }

            return $token['access_token'];
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Delivery Firebase authentication failed: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    private function normalizeData(array $data): array
    {
        return collect($data)
            ->mapWithKeys(fn ($value, $key) => [
                (string) $key => is_scalar($value)
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE),
            ])
            ->all();
    }
}