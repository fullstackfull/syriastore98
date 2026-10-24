<?php

namespace Tests\Support\SocialIdentity;

use Firebase\JWT\JWT;

/**
 * An OpenID Connect provider standing in for Google or Apple, entirely inside the test process.
 *
 * WHY THIS AND NOT A STUBBED VERIFIER. Stubbing the verifier would leave the thing under test --
 * the signature, issuer, audience and expiry checks -- never executed, so every "refused" assertion
 * would pass against a verifier that checked nothing. This generates a real RSA keypair, publishes
 * a real JWKS for it, and signs real RS256 tokens, so App\Services\SocialIdentity\
 * SocialIdentityVerifier runs its actual code path. The only thing faked is the HTTP hop, through
 * the `Http` facade.
 *
 * Nothing leaves the process: no network, no provider credentials, no real tokens.
 */
class FakeOidcProvider
{
    private string $privateKey;

    /** @var array{n: string, e: string} */
    private array $publicNumbers;

    public function __construct(public readonly string $keyId = 'test-key-1')
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new \RuntimeException('openssl could not generate a test keypair');
        }

        openssl_pkey_export($resource, $privateKey);
        $this->privateKey = $privateKey;

        $details = openssl_pkey_get_details($resource);
        $this->publicNumbers = [
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
    }

    /** The key set this provider would publish at its JWKS URL. */
    public function jwks(): array
    {
        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $this->keyId,
                'n' => $this->publicNumbers['n'],
                'e' => $this->publicNumbers['e'],
            ]],
        ];
    }

    /**
     * Sign a token. Every claim is overridable so a test can produce a token that is correct in
     * every respect except the one being tested.
     */
    public function token(array $claims = []): string
    {
        $now = time();

        $payload = array_merge([
            'iss' => 'https://accounts.google.com',
            'sub' => 'provider-subject-1',
            'aud' => 'configured-audience',
            'iat' => $now - 10,
            'exp' => $now + 3600,
        ], $claims);

        // Drop keys explicitly set to null so a test can produce a token with NO sub, NO aud, etc.
        $payload = array_filter($payload, static fn ($v) => $v !== null);

        return JWT::encode($payload, $this->privateKey, 'RS256', $this->keyId);
    }

    /** A token signed by a DIFFERENT key than the one this provider publishes. */
    public function tokenSignedByAnotherKey(array $claims = []): string
    {
        $impostor = new self($this->keyId);

        return $impostor->token($claims);
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
