<?php

namespace Tests\Feature\Security;

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
 * WHAT RUNS HERE -- REWRITTEN ON 2026-11-02, AND THE OLD TEXT IS KEPT BECAUSE IT RECORDS A
 * PROOF TECHNIQUE THIS PROJECT SHOULD NOT USE AGAIN. It read:
 *
 *     `zid_events` is declared by no migration ... So the ACCEPTED direction cannot be
 *     asserted on the response; it is asserted on the QueryException from the insert, which
 *     proves the request got through the credential gate and all the way to the write.
 *
 * That was true and it was the honest best available, but it made a MISSING TABLE the proof
 * that a security gate had been passed. GATE F closed on 2026-11-02, `zid_events` exists, the
 * insert succeeds, and both positive tests failed on their own `$this->fail()`.
 *
 * WHAT IS ASSERTED NOW, in both directions and on the real thing:
 *
 *   ACCEPTED -- the HTTP response (200, `{"ok":true}`) AND the `zid_events` row the handler
 *   wrote, read back by store and event. A status alone would not distinguish "accepted" from
 *   "accepted and then silently failed to record anything".
 *
 *   DENIED -- the 401 AND the absence of any `zid_events` row. The refusal happens before any
 *   database access, so the row count is the direct observation that nothing happened; before
 *   GATE F it could not be made at all.
 *
 *   AND THE DESTRUCTIVE WRITE, BOTH WAYS. The finding above says the fail-open exposed a
 *   caller-chosen DELETE on `zid_stores`. Until GATE F that claim was unverifiable here: the
 *   anonymous-uninstall test asserted a 401 and nothing else, so it would have passed just as
 *   happily against a handler that no longer deleted anything -- and the severity of the
 *   finding rests entirely on that delete existing. There are now two tests: the authenticated
 *   uninstall DOES remove the named `zid_stores` row, and the anonymous one does NOT, with the
 *   row read back in both cases.
 *
 * ONE THING THE POSITIVE TESTS DO NOT PROVE, stated rather than implied: `zid_events.store_id`
 * and `zid_stores.store_id` are varchar columns holding **Zid's own merchant id**, written from
 * Zid's API (ZidOAuthController.php:80-83), not `stores.id`. So "the caller-named store" below
 * means a Zid merchant, and none of this is a Store Syria tenancy assertion. See the
 * foreign-namespace bucket in tests/Feature/Tenancy/RawQueryTenancyInventoryTest.
 */
class ZidWebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/zid/events/v1';

    /** A fixture secret. The configured value is never used in a test. */
    private const SECRET = 'zid-hook-secret-for-tests-only';

    private const HOOK_USER = 'zid-hook-user-for-tests-only';

    /**
     * A ZID MERCHANT ID, not a `stores.id`.
     *
     * `zid_events.store_id` is varchar(255) and `zid_stores.store_id` is varchar(255) with a
     * UNIQUE key, and both hold the id Zid's own API reports (ZidOAuthController.php:80-83
     * reads `user.store.id` from api.zid.sa). A deliberately non-numeric value is used here so
     * that nothing in this file can quietly pass by comparing it to a Store Syria store id --
     * on MySQL a varchar compared to an integer binding is coerced to DOUBLE, which makes
     * `'2abc' = 2` TRUE, and SQLite cannot show that.
     */
    private const ZID_MERCHANT = 'zid-merchant-9f3a';

    /** @return \Illuminate\Database\Query\Builder */
    private function events()
    {
        return \Illuminate\Support\Facades\DB::table('zid_events');
    }

    /**
     * A linked Zid merchant, shaped from the measured production structure: `store_id`,
     * `authorization` and `access_token` are NOT NULL, `refresh_token` and `ds_secret` are
     * nullable text, and `store_id` carries a UNIQUE key.
     */
    private function linkZidMerchant(string $zidMerchantId): void
    {
        \Illuminate\Support\Facades\DB::table('zid_stores')->insert([
            'store_id' => $zidMerchantId,
            'authorization' => 'fixture-authorization',
            'access_token' => 'fixture-access-token',
            'refresh_token' => 'fixture-refresh-token',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function zidMerchantIsLinked(string $zidMerchantId): bool
    {
        return \Illuminate\Support\Facades\DB::table('zid_stores')
            ->where('store_id', $zidMerchantId)
            ->exists();
    }

    private function configure(?string $secret, ?string $user = self::HOOK_USER): void
    {
        config([
            'services.zid.hook_pass' => $secret,
            'services.zid.hook_user' => $user,
        ]);
    }

    /**
     * POSITIVE DIRECTION, header arm: Zid's own call still works, and is RECORDED.
     */
    public function test_the_configured_secret_in_the_header_is_accepted(): void
    {
        $this->configure(self::SECRET);
        $this->withoutExceptionHandling();

        $this->assertSame(0, $this->events()->count(), 'premise: no event recorded yet');

        $response = $this->postJson(
            self::URI,
            ['store_id' => self::ZID_MERCHANT, 'event' => 'order.create'],
            ['X-Hook-Secret' => self::SECRET]
        );

        $response->assertOk();
        $this->assertTrue((bool) $response->json('ok'), 'the handler did not answer ok');

        $this->assertSame(
            1,
            $this->events()->where('store_id', self::ZID_MERCHANT)->where('event', 'order.create')->count(),
            'the request passed the credential gate but no `zid_events` row was written, so the '
            . 'handler accepted it and recorded nothing. Until GATE F closed on 2026-11-02 this '
            . 'was asserted on a QueryException from a table no migration created.'
        );
    }

    /**
     * POSITIVE DIRECTION, basic-auth arm: the second way Zid is configured to call this.
     */
    public function test_the_configured_basic_auth_credentials_are_accepted(): void
    {
        $this->configure(self::SECRET);
        $this->withoutExceptionHandling();

        $response = $this->postJson(
            self::URI,
            ['store_id' => self::ZID_MERCHANT, 'event' => 'order.create'],
            ['Authorization' => 'Basic ' . base64_encode(self::HOOK_USER . ':' . self::SECRET)]
        );

        $response->assertOk();
        $this->assertTrue((bool) $response->json('ok'), 'the handler did not answer ok');

        $this->assertSame(
            1,
            $this->events()->where('store_id', self::ZID_MERCHANT)->where('event', 'order.create')->count(),
            'the basic-auth arm passed the credential gate but wrote no `zid_events` row'
        );
    }

    /**
     * The 401 AND the absence of a row. Before GATE F the second half could not be observed at
     * all, so a refusal test could not distinguish "refused" from "admitted and then crashed".
     */
    public function test_a_wrong_header_secret_is_refused(): void
    {
        $this->configure(self::SECRET);

        $this->postJson(self::URI, ['store_id' => self::ZID_MERCHANT], ['X-Hook-Secret' => 'wrong'])
            ->assertStatus(401);

        $this->assertSame(0, $this->events()->count(), 'a refused request still recorded an event');
    }

    public function test_wrong_basic_auth_credentials_are_refused(): void
    {
        $this->configure(self::SECRET);

        $this->postJson(
            self::URI,
            ['store_id' => self::ZID_MERCHANT],
            ['Authorization' => 'Basic ' . base64_encode(self::HOOK_USER . ':wrong')]
        )->assertStatus(401);

        $this->assertSame(0, $this->events()->count(), 'a refused request still recorded an event');
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
     * THE DESTRUCTIVE PAYLOAD, which is what gives the fail-open its severity:
     * an `application.uninstall` event DELETEs a `zid_stores` row chosen by the caller.
     *
     * STRENGTHENED ON 2026-11-02. This used to assert the 401 and nothing else. That is a
     * refusal test whose subject could disappear without it noticing: if the DELETE at
     * routes/web.php:1683 were removed, this test would stay green while the finding it records
     * -- "the handler ... DELETEs the caller-named store's `zid_stores` row -- a destructive
     * cross-tenant write" -- quietly became unverified. `zid_stores` was declared by no
     * migration, so the row could not be seeded and the claim could not be checked here at all.
     *
     * It now reads the row back, and the sibling test below drives the SAME payload with a valid
     * secret and proves the DELETE really happens. The pair is the point: either test alone can
     * pass for the wrong reason.
     */
    public function test_the_uninstall_payload_cannot_be_sent_anonymously_when_no_secret_is_configured(): void
    {
        $this->linkZidMerchant(self::ZID_MERCHANT);
        $this->assertTrue($this->zidMerchantIsLinked(self::ZID_MERCHANT), 'premise: the merchant is linked');

        $this->configure(null, null);

        $this->postJson(self::URI, [
            'store_id' => self::ZID_MERCHANT,
            'event' => 'application.uninstall',
        ])->assertStatus(401);

        $this->assertTrue(
            $this->zidMerchantIsLinked(self::ZID_MERCHANT),
            'AN ANONYMOUS CALLER UNLINKED A ZID MERCHANT. The uninstall payload reached the '
            . 'DELETE at routes/web.php:1683 although no secret is configured, which is '
            . 'NEW-ZID-HOOK-FAILOPEN-01 itself.'
        );

        $this->assertSame(0, $this->events()->count(), 'a refused request still recorded an event');
    }

    /**
     * THE POSITIVE HALF OF THE SAME PAIR, and it is only possible because GATE F declared
     * `zid_stores` on 2026-11-02.
     *
     * Its job is NOT to approve the behaviour -- a caller-chosen DELETE on an anonymous endpoint
     * is exactly the surface the finding is about. Its job is to make the refusal above provable:
     * a 401 only matters if the thing being refused would otherwise happen.
     *
     * THAT BREADTH WAS A DEFECT AND IS FIXED, which is why this docblock no longer merely notes it.
     * The handler used to match against `$raw . ' ' . $event` where `$raw` is the WHOLE serialised
     * body, so any authenticated webhook containing the string anywhere unlinked the named
     * merchant -- measured, with a product name, while `zid_events.event` recorded `order.create`.
     * See NEW-ZID-UNINSTALL-SUBSTRING-01 in routes/web.php. The condition now reads the resolved
     * event name, and `test_a_non_uninstall_event_mentioning_the_string_does_not_unlink_anyone`
     * below is the regression test for it.
     *
     * The id is a ZID merchant id, so none of this is a Store Syria tenancy boundary.
     */
    public function test_an_authenticated_uninstall_does_remove_the_named_zid_merchant(): void
    {
        $this->linkZidMerchant(self::ZID_MERCHANT);
        $this->linkZidMerchant('zid-merchant-bystander');

        $this->configure(self::SECRET);
        $this->withoutExceptionHandling();

        $this->postJson(
            self::URI,
            ['store_id' => self::ZID_MERCHANT, 'event' => 'application.uninstall'],
            ['X-Hook-Secret' => self::SECRET]
        )->assertOk();

        $this->assertFalse(
            $this->zidMerchantIsLinked(self::ZID_MERCHANT),
            'The authenticated uninstall did NOT delete the named `zid_stores` row. If that is '
            . 'now the intended behaviour the sibling refusal test above is no longer proving '
            . 'anything and must be rewritten with it -- do not leave one of the pair standing.'
        );

        $this->assertTrue(
            $this->zidMerchantIsLinked('zid-merchant-bystander'),
            'the uninstall removed a merchant the payload did not name'
        );

        $this->assertSame(
            1,
            $this->events()->where('event', 'application.uninstall')->count(),
            'the uninstall was not recorded in `zid_events`'
        );
    }

    /**
     * NEW-ZID-UNINSTALL-SUBSTRING-01 -- THE REGRESSION TEST FOR A DESTRUCTIVE WRITE THAT FIRED ON
     * MERCHANT-CONTROLLED CONTENT.
     *
     * The unlink condition used to search the entire serialised request body:
     *
     *     str_contains($raw . ' ' . $event, 'application.uninstall')
     *
     * so an ordinary `order.create` webhook carrying the string in a PRODUCT NAME deleted the
     * merchant's `zid_stores` row. Measured before the fix: HTTP 200, `zid_events.event` recorded
     * as `order.create`, and zero rows left in `zid_stores`. The handler recorded one thing and
     * did another, so even the audit row would not have explained the unlink. Product names, order
     * notes and addresses are content Zid relays verbatim, so this needed no crafted request.
     *
     * It was unobservable until GATE F declared `zid_stores` on 2026-11-02 -- no environment built
     * from this repository had a row to lose.
     *
     * THE BYSTANDER IS PART OF THE ASSERTION. A fix that simply stopped deleting anything would
     * also pass this test, which is why the sibling above drives a REAL uninstall and proves the
     * DELETE still happens.
     */
    public function test_a_non_uninstall_event_mentioning_the_string_does_not_unlink_anyone(): void
    {
        $this->linkZidMerchant(self::ZID_MERCHANT);

        $this->configure(self::SECRET);
        $this->withoutExceptionHandling();

        $this->postJson(
            self::URI,
            [
                'store_id' => self::ZID_MERCHANT,
                'event' => 'order.create',
                'order' => ['items' => [['name' => 'Guide to application.uninstall webhooks (paperback)']]],
            ],
            ['X-Hook-Secret' => self::SECRET]
        )->assertOk();

        $this->assertTrue(
            $this->zidMerchantIsLinked(self::ZID_MERCHANT),
            'A MERCHANT WAS UNLINKED BY A PRODUCT NAME. The unlink condition is matching a '
            . 'substring of the whole request body again instead of the resolved event name, so '
            . 'any content Zid relays can destroy OAuth credentials that cannot be reconstructed '
            . 'without re-authorising. This is NEW-ZID-UNINSTALL-SUBSTRING-01.'
        );

        $this->assertSame(
            'order.create',
            $this->events()->value('event'),
            'the recorded event name is not the one the request carried'
        );
    }

    /**
     * THE BASIC-AUTH USER COMPARISON HAS ITS OWN TEST, because nothing else made it bite.
     *
     * `test_wrong_basic_auth_credentials_are_refused` sends the RIGHT user with a wrong password,
     * so it reddens if either comparison is removed -- and therefore proves neither. Deleting
     * `hash_equals($configuredUser, $basicUser)` from the gate left every test in this class green
     * before this one existed, which was found by independent review running that exact mutation
     * rather than by reading the file.
     */
    public function test_the_right_password_under_a_wrong_user_is_refused(): void
    {
        $this->configure(self::SECRET);

        $this->postJson(
            self::URI,
            ['store_id' => self::ZID_MERCHANT],
            ['Authorization' => 'Basic ' . base64_encode('not-the-hook-user:' . self::SECRET)]
        )->assertStatus(401);

        $this->assertSame(0, $this->events()->count(), 'a refused request still recorded an event');
    }
}
