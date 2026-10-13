<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Services\PaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WHERE THE PAYMERA STATUS CREDENTIAL COMES FROM.
 *
 * THE DEFECT. PaymentController::paymeraStatusCredential() read
 * `config('services.paymera.username')` and nothing else. That key has never
 * existed in any deployment -- it dates to the initial commit (3c39c160) -- so
 * the credential was always null, pollPaymentStatus() always abandoned, and
 * PAYMERA CHECKOUT COULD NEVER COMPLETE. Not "regressed": never able to
 * complete.
 *
 * Meanwhile the credential the merchant fills in is a PER-STORE SETTING:
 * `Utility::GetValueByName('PAYMERA_USERNAME', $store->id)`, which is what
 * PaymentService::processPaymera() already uses to authorise the CREATE-payment
 * call (PaymentService.php:340, :428) and what the settings screen writes
 * (PaymentSettingController.php:177-178 lists PAYMERA_TERMINAL_ID and
 * PAYMERA_USERNAME as Paymera's required_fields). On a shared-database
 * multi-tenant platform one global env var cannot express a per-store
 * credential, so the env key was never going to be right.
 *
 * WHAT THIS SUITE PROVES: that the SOURCE is now the per-store setting, that it
 * is correctly scoped per store, that the global key still works as a fallback,
 * that the flag can switch the behaviour back, and that the whole thing still
 * FAILS CLOSED when no credential exists anywhere.
 *
 * WHAT IT DOES NOT PROVE, stated plainly because the owner asked for exactly
 * this distinction: nothing here establishes anything about Paymera. It is not
 * verified that Paymera accepts this value as a bearer token on the status API,
 * nor that PAYMERA_USERNAME is the right field for that API rather than only for
 * the create call. No Paymera host is reachable from this container and none was
 * contacted. The gateway integration stays OPEN / BLOCKED on the provider's own
 * document; only the CONFIGURATION SOURCE is fixed and tested here.
 *
 * NO CREDENTIAL VALUE IS REAL. The fixture writes the literal string
 * 'per-store-sentinel', which is not a credential and is not used against
 * anything.
 */
class PaymeraStatusCredentialSourceTest extends TestCase
{
    private const STORE_A = 11;
    private const STORE_B = 22;

    /** A deliberately fake marker, so a failure message can never leak a secret. */
    private const SENTINEL_A = 'per-store-sentinel-A';
    private const SENTINEL_B = 'per-store-sentinel-B';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->longText('value')->nullable();
            $table->string('created_by');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    /** The per-store setting is used when it exists. */
    public function test_the_per_store_setting_is_the_credential_source(): void
    {
        $this->setSetting(self::STORE_A, 'PAYMERA_USERNAME', self::SENTINEL_A);

        $this->assertSame(
            self::SENTINEL_A,
            $this->credentialFor(self::STORE_A),
            'the credential must come from the store setting the merchant filled in'
        );
    }

    /**
     * IT IS SCOPED PER STORE. Store B's credential is never handed to store A.
     *
     * This is the property the global env var could not express at all, and it is
     * the reason the repoint is a fix rather than a preference.
     */
    public function test_one_stores_credential_is_never_used_for_another(): void
    {
        $this->setSetting(self::STORE_A, 'PAYMERA_USERNAME', self::SENTINEL_A);
        $this->setSetting(self::STORE_B, 'PAYMERA_USERNAME', self::SENTINEL_B);

        $this->assertSame(self::SENTINEL_A, $this->credentialFor(self::STORE_A));
        $this->assertSame(self::SENTINEL_B, $this->credentialFor(self::STORE_B));
    }

    /** A store with no setting of its own gets no credential from a sibling. */
    public function test_a_store_without_a_setting_gets_no_credential(): void
    {
        $this->setSetting(self::STORE_B, 'PAYMERA_USERNAME', self::SENTINEL_B);

        $this->assertNull(
            $this->credentialFor(self::STORE_A),
            "store A must not inherit store B's credential"
        );
    }

    /**
     * FAILS CLOSED. No setting and no config key means no credential, which means
     * pollPaymentStatus() makes no call and returns the unauthenticated sentinel.
     */
    public function test_no_credential_anywhere_still_fails_closed(): void
    {
        $this->assertNull($this->credentialFor(self::STORE_A));

        config(['services.paymera.username' => null]);

        $result = (new PaymentController(app(PaymentService::class)))
            ->pollPaymentStatus('PAY-1', self::STORE_A);

        $this->assertSame(
            PaymentController::PAYMERA_STATUS_UNAUTHENTICATED,
            $result['status'] ?? null,
            'an unauthenticable poll must never be mistaken for a decline or a capture'
        );
    }

    /** The global key still works as a fallback for a single-tenant deployment. */
    public function test_the_global_config_key_remains_a_fallback(): void
    {
        config(['services.paymera.username' => 'global-sentinel']);

        $this->assertSame(
            'global-sentinel',
            $this->credentialFor(self::STORE_A),
            'a deployment that had set the env var must keep working'
        );
    }

    /** The per-store setting WINS over the global key when both exist. */
    public function test_the_per_store_setting_takes_precedence_over_the_global_key(): void
    {
        config(['services.paymera.username' => 'global-sentinel']);
        $this->setSetting(self::STORE_A, 'PAYMERA_USERNAME', self::SENTINEL_A);

        $this->assertSame(
            self::SENTINEL_A,
            $this->credentialFor(self::STORE_A),
            'the per-store value is the one the merchant actually configured'
        );
    }

    /**
     * THE ESCAPE HATCH. The owner can switch back to the global-only lookup
     * without a code change.
     */
    public function test_the_flag_restores_the_global_only_lookup(): void
    {
        config(['services.paymera.status_credential_from_store_setting' => false]);
        config(['services.paymera.username' => null]);
        $this->setSetting(self::STORE_A, 'PAYMERA_USERNAME', self::SENTINEL_A);

        $this->assertNull(
            $this->credentialFor(self::STORE_A),
            'with the flag off the per-store setting must be ignored, which is '
                . 'exactly the pre-fix behaviour'
        );
    }

    /** With no store in scope only the global key is consulted. */
    public function test_with_no_store_in_scope_only_the_global_key_is_read(): void
    {
        $this->setSetting(self::STORE_A, 'PAYMERA_USERNAME', self::SENTINEL_A);
        config(['services.paymera.username' => null]);

        $this->assertNull(
            $this->credentialFor(null),
            'a null store must not silently fall through to some other store'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The gateway host
    |--------------------------------------------------------------------------
    */

    /**
     * The host default is byte-for-byte the value that was hardcoded, so this
     * change moves the host into configuration WITHOUT repointing anyone's
     * gateway.
     *
     * `egate-t` is Paymera's TEST gateway. That it is still the default is
     * reported, not fixed: which host production should use is Paymera's to say.
     */
    public function test_the_status_host_default_is_the_previously_hardcoded_one(): void
    {
        $this->assertSame(
            'https://egate-t.paymera.cc',
            config('services.paymera.status_base_url'),
            'the default must preserve the hardcoded host exactly'
        );
    }

    /** The host is no longer written into the call. */
    public function test_the_status_host_is_not_hardcoded_in_the_poll_any_more(): void
    {
        $m = new \ReflectionMethod(PaymentController::class, 'pollPaymentStatus');

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        $this->assertStringContainsString(
            "config('services.paymera.status_base_url'",
            $code,
            'the host must be read from configuration'
        );
        $this->assertStringNotContainsString(
            "'https://egate-t.paymera.cc/api/payment-status/",
            $code,
            'the host must not be concatenated into the call as a literal any more'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function setSetting(int $storeId, string $name, string $value): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->insert([
            'name' => $name,
            'value' => $value,
            'created_by' => '1',
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Utility::GetValueByName goes through laravel-model-caching, and a raw
        // DB::table() insert fires no Eloquent event, so the cache is not
        // invalidated by it. Flushed explicitly here; production writes go
        // through the Setting model and do invalidate.
        (new \App\Models\Setting())->flushCache();
    }

    /** The method is private by design; reflection keeps it that way. */
    private function credentialFor($storeId): ?string
    {
        $m = new \ReflectionMethod(PaymentController::class, 'paymeraStatusCredential');
        $m->setAccessible(true);

        return $m->invoke(new PaymentController(app(PaymentService::class)), $storeId);
    }
}
