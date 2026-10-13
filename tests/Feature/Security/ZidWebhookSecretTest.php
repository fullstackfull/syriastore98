<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NEW-ZID-HOOK-FAILOPEN-01.
 *
 * POST /zid/events/{v?} (routes/web.php) is an anonymous endpoint by necessity -- Zid's
 * servers call it -- gated only by a shared secret. It used to read:
 *
 *     $allowed = $r->header('X-Hook-Secret') === config('services.zid.hook_pass')
 *         || ($r->getUser() === config('services.zid.hook_user')
 *             && $r->getPassword() === config('services.zid.hook_pass'));
 *
 * Request::header() returns NULL for an absent header, and getUser()/getPassword() return
 * NULL with no basic-auth credentials, so an UNSET configured secret turned both arms into
 * `null === null` and admitted every anonymous request. The handler inserts into
 * `zid_events` and, on an `application.uninstall` payload, DELETEs the caller-named
 * store's `zid_stores` row -- a destructive cross-tenant write.
 *
 * Latent at HEAD (config/services.php defines both values, which are NOT reproduced here
 * or in any test fixture) and live the moment those literals move into the environment,
 * which is what SEC-CFG-02 asks for. That is the whole point: the fail-open was waiting
 * for the remediation of another finding.
 *
 * WHAT RUNS HERE. `zid_events` is declared by no migration -- it is in the same
 * undeclared-schema family as `zid_orders`/`zid_stores`, parked under
 * database/migrations/_gate_f_pending/. So the ACCEPTED direction cannot be asserted on
 * the response; it is asserted on the QueryException from the insert, which proves the
 * request got through the credential gate and all the way to the write. The DENIED
 * direction is asserted on the real HTTP response, because the refusal happens before any
 * database access.
 */
class ZidWebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/zid/events/v1';

    /** A fixture secret. The configured value is never used in a test. */
    private const SECRET = 'zid-hook-secret-for-tests-only';

    private const HOOK_USER = 'zid-hook-user-for-tests-only';

    private function configure(?string $secret, ?string $user = self::HOOK_USER): void
    {
        config([
            'services.zid.hook_pass' => $secret,
            'services.zid.hook_user' => $user,
        ]);
    }

    /**
     * POSITIVE DIRECTION, header arm: Zid's own call still works.
     */
    public function test_the_configured_secret_in_the_header_is_accepted(): void
    {
        $this->configure(self::SECRET);
        $this->withoutExceptionHandling();

        try {
            $this->postJson(
                self::URI,
                ['store_id' => 1, 'event' => 'order.create'],
                ['X-Hook-Secret' => self::SECRET]
            );

            $this->fail(
                'The request should have passed the credential gate and failed on the '
                . 'undeclared `zid_events` table instead.'
            );
        } catch (QueryException $e) {
            $this->assertStringContainsString('zid_events', $e->getMessage());
        }
    }

    /**
     * POSITIVE DIRECTION, basic-auth arm: the second way Zid is configured to call this.
     */
    public function test_the_configured_basic_auth_credentials_are_accepted(): void
    {
        $this->configure(self::SECRET);
        $this->withoutExceptionHandling();

        try {
            $this->postJson(
                self::URI,
                ['store_id' => 1, 'event' => 'order.create'],
                ['Authorization' => 'Basic ' . base64_encode(self::HOOK_USER . ':' . self::SECRET)]
            );

            $this->fail(
                'The basic-auth arm should have passed the credential gate and failed on '
                . 'the undeclared `zid_events` table instead.'
            );
        } catch (QueryException $e) {
            $this->assertStringContainsString('zid_events', $e->getMessage());
        }
    }

    public function test_a_wrong_header_secret_is_refused(): void
    {
        $this->configure(self::SECRET);

        $this->postJson(self::URI, ['store_id' => 1], ['X-Hook-Secret' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_wrong_basic_auth_credentials_are_refused(): void
    {
        $this->configure(self::SECRET);

        $this->postJson(
            self::URI,
            ['store_id' => 1],
            ['Authorization' => 'Basic ' . base64_encode(self::HOOK_USER . ':wrong')]
        )->assertStatus(401);
    }

    /**
     * THE FIX ITSELF. No secret configured and no credentials offered: the comparison
     * used to be null === null, so this request was ADMITTED and reached the writes.
     */
    public function test_an_anonymous_request_is_refused_when_no_secret_is_configured(): void
    {
        $this->configure(null, null);

        $this->postJson(self::URI, ['store_id' => 1, 'event' => 'order.create'])
            ->assertStatus(401);
    }

    /**
     * The same hole through the basic-auth arm, which needed its own guard: with both
     * configured values unset, `getUser() === null` and `getPassword() === null` also
     * made $allowed true.
     */
    public function test_an_empty_basic_auth_header_is_refused_when_no_secret_is_configured(): void
    {
        $this->configure('', '');

        $this->postJson(
            self::URI,
            ['store_id' => 1],
            ['Authorization' => 'Basic ' . base64_encode(':')]
        )->assertStatus(401);
    }

    /**
     * And the destructive payload specifically, since that is what the fail-open exposed:
     * an `application.uninstall` event deletes a `zid_stores` row chosen by the caller.
     */
    public function test_the_uninstall_payload_cannot_be_sent_anonymously_when_no_secret_is_configured(): void
    {
        $this->configure(null, null);

        $this->postJson(self::URI, [
            'store_id' => 1,
            'event' => 'application.uninstall',
        ])->assertStatus(401);
    }
}
