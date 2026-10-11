<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebuzoDomainService
{
    protected string $host;
    protected string $username;
    protected string $apiKey;
    protected int $port;
    protected bool $verifySsl;

    public function __construct()
    {
        $this->host = rtrim((string) config('services.webuzo.host'), '/');
        $this->username = (string) config('services.webuzo.username');
        $this->apiKey = (string) config('services.webuzo.api_key');
        $this->port = (int) config('services.webuzo.port', 2003);
        $this->verifySsl = (bool) config('services.webuzo.verify_ssl', true);
    }

    /**
     * Add a custom domain to Webuzo as a Parked Domain.
     */
    public function addDomain(string $domain, bool $issueSsl = false): array
    {
        $domain = $this->normalizeDomain($domain);

        if (!$domain) {
            return $this->failure('Invalid domain.');
        }

        try {
            $response = $this->request('domainadd', [
                'add'          => 1,
                'domain_type'  => 'parked',
                'domain'       => $domain,
                'wildcard'     => 0,
                'issue_lecert' => $issueSsl ? 1 : 0,
            ]);

            if ($this->responseSuccessful($response)) {
                return [
                    'success' => true,
                    'domain'  => $domain,
                    'message' => $this->extractSuccessMessage(
                        $response,
                        'Domain added successfully.'
                    ),
                    'response' => $response,
                ];
            }

            return $this->failure(
                $this->extractErrorMessage(
                    $response,
                    'Webuzo could not add the domain.'
                ),
                $response
            );

        } catch (Throwable $e) {
            Log::error('Webuzo add domain failed', [
                'domain' => $domain,
                'error'  => $e->getMessage(),
            ]);

            return $this->failure(
                'Could not connect to Webuzo: ' . $e->getMessage()
            );
        }
    }

    /**
     * Delete a domain from Webuzo.
     */
    public function deleteDomain(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);

        if (!$domain) {
            return $this->failure('Invalid domain.');
        }

        try {
            $response = $this->request('domainmanage', [
                'delete' => $domain,
            ]);

            if ($this->responseSuccessful($response)) {
                return [
                    'success' => true,
                    'domain'  => $domain,
                    'message' => $this->extractSuccessMessage(
                        $response,
                        'Domain deleted successfully.'
                    ),
                    'response' => $response,
                ];
            }

            return $this->failure(
                $this->extractErrorMessage(
                    $response,
                    'Webuzo could not delete the domain.'
                ),
                $response
            );

        } catch (Throwable $e) {
            Log::error('Webuzo delete domain failed', [
                'domain' => $domain,
                'error'  => $e->getMessage(),
            ]);

            return $this->failure(
                'Could not connect to Webuzo: ' . $e->getMessage()
            );
        }
    }

    /**
     * Add a new domain first, then delete the old domain.
     *
     * Important:
     * We do NOT delete the old domain before making sure
     * the new one was successfully added.
     */
    public function replaceDomain(
        string $oldDomain,
        string $newDomain,
        bool $issueSsl = false
    ): array {
        $oldDomain = $this->normalizeDomain($oldDomain);
        $newDomain = $this->normalizeDomain($newDomain);

        if (!$newDomain) {
            return $this->failure('Invalid new domain.');
        }

        if ($oldDomain === $newDomain) {
            return [
                'success' => true,
                'domain' => $newDomain,
                'message' => 'Domain has not changed.',
            ];
        }

        // First add the new domain.
        $addResult = $this->addDomain($newDomain, $issueSsl);

        if (!$addResult['success']) {
            return $addResult;
        }

        // If there was no old domain, we're done.
        if (!$oldDomain) {
            return $addResult;
        }

        // New domain was added successfully.
        // Now remove the old one.
        $deleteResult = $this->deleteDomain($oldDomain);

        if (!$deleteResult['success']) {
            Log::warning('New Webuzo domain added but old domain could not be deleted', [
                'old_domain' => $oldDomain,
                'new_domain' => $newDomain,
                'delete_result' => $deleteResult,
            ]);

            return [
                'success' => true,
                'partial' => true,
                'domain' => $newDomain,
                'old_domain' => $oldDomain,
                'message' => 'New domain was added, but the old domain could not be removed.',
                'add_result' => $addResult,
                'delete_result' => $deleteResult,
            ];
        }

        return [
            'success' => true,
            'partial' => false,
            'domain' => $newDomain,
            'old_domain' => $oldDomain,
            'message' => 'Domain replaced successfully.',
            'add_result' => $addResult,
            'delete_result' => $deleteResult,
        ];
    }

    /**
     * Make a request to Webuzo Enduser API.
     */
    protected function request(string $action, array $data = []): array
    {
        $this->validateConfiguration();

        $url = sprintf(
            'https://%s:%d/index.php?api=json&act=%s',
            $this->host,
            $this->port,
            urlencode($action)
        );

        $payload = array_merge([
            'apiuser' => $this->username,
            'apikey'  => $this->apiKey,
        ], $data);

        $http = Http::asForm()
            ->connectTimeout(10)
            ->timeout(30);

        /*
         * Only disable certificate verification if your Webuzo
         * hostname currently has an invalid/self-signed certificate.
         */
        if (!$this->verifySsl) {
            $http = $http->withoutVerifying();
        }

        $response = $http->post($url, $payload);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Webuzo HTTP error: ' . $response->status()
            );
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new \RuntimeException(
                'Invalid JSON response received from Webuzo.'
            );
        }

        return $json;
    }

    /**
     * Determine whether Webuzo considers the request successful.
     */
    protected function responseSuccessful(array $response): bool
    {
        if (!empty($response['error'])) {
            return false;
        }

        return !empty($response['done']);
    }

    /**
     * Extract success message from Webuzo response.
     */
    protected function extractSuccessMessage(
        array $response,
        string $fallback
    ): string {
        if (isset($response['done']['msg'])) {
            if (is_array($response['done']['msg'])) {
                return implode(' ', $response['done']['msg']);
            }

            return (string) $response['done']['msg'];
        }

        if (isset($response['done']) && is_string($response['done'])) {
            return $response['done'];
        }

        return $fallback;
    }

    /**
     * Extract error message from Webuzo response.
     */
    protected function extractErrorMessage(
        array $response,
        string $fallback
    ): string {
        if (!isset($response['error'])) {
            return $fallback;
        }

        if (is_string($response['error'])) {
            return $response['error'];
        }

        if (is_array($response['error'])) {
            return $this->arrayToString($response['error']) ?: $fallback;
        }

        return $fallback;
    }

    /**
     * Convert nested Webuzo error arrays to readable text.
     */
    protected function arrayToString(array $errors): string
    {
        $messages = [];

        array_walk_recursive($errors, function ($value) use (&$messages) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $messages[] = trim((string) $value);
            }
        });

        return implode(' ', array_unique($messages));
    }

    /**
     * Normalize a domain.
     *
     * https://www.example.com/
     * becomes:
     * example.com
     */
    public function normalizeDomain(?string $domain): ?string
    {
        if (!$domain) {
            return null;
        }

        $domain = trim(strtolower($domain));

        if ($domain === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $domain)) {
            $domain = 'http://' . $domain;
        }

        $host = parse_url($domain, PHP_URL_HOST);

        if (!$host) {
            return null;
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./i', '', $host);
        $host = rtrim($host, '.');

        if (
            !filter_var(
                $host,
                FILTER_VALIDATE_DOMAIN,
                FILTER_FLAG_HOSTNAME
            )
        ) {
            return null;
        }

        return $host;
    }

    /**
     * Standard failure response.
     */
    protected function failure(
        string $message,
        array $response = []
    ): array {
        return [
            'success' => false,
            'message' => $message,
            'response' => $response,
        ];
    }

    /**
     * Make sure required configuration exists.
     */
    protected function validateConfiguration(): void
    {
        if (!$this->host) {
            throw new \RuntimeException('WEBUZO_HOST is not configured.');
        }

        if (!$this->username) {
            throw new \RuntimeException('WEBUZO_USERNAME is not configured.');
        }

        if (!$this->apiKey) {
            throw new \RuntimeException('WEBUZO_API_KEY is not configured.');
        }
    }




public function addSubdomain(
    string $domain,
    string $subdomain,
    bool $issueSsl = false
): array {

    $domain = $this->normalizeDomain($domain);
    $subdomain = strtolower(trim($subdomain));

    return $this->request('domainadd', [
        'add' => 1,
        'domain_type' => 'subdomain',
        'domain' => $domain,
        'subdomain' => $subdomain,
  'domainpath' => 'public_html',
        'wildcard' => 0,
        'issue_lecert' => $issueSsl ? 1 : 0,
    ]);
}

public function domainExists(string $domain): bool
{
    $domain = $this->normalizeDomain($domain);

    $result = $this->request('domainmanage', []);

    $domains = $result['domains_list'] ?? [];

    return array_key_exists($domain, $domains);
}

}