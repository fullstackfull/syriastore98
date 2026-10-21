<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P1-SEC-15 -- `POST /api/{slug}/payment-list` RETURNS THE STORE'S GATEWAY SECRET KEYS.
 * =============================================================================================
 *
 * Found by the P1-SEC-13 sibling-sink review (READ), measured here. `ApiController::payment_list`
 * builds the checkout's payment-method list and, for each gateway, puts the merchant's SECRET
 * credential in the same entry as the display fields (`stripe_secret_key`, `paystack_secret`,
 * the PayTM merchant key, ...). The route carries `custom.auth` only, and `getStore($slug)` takes
 * the store from the URL with no relation to the caller -- so any holder of ANY Sanctum token
 * (a shopper of another store, a merchant, a driver) names a victim store's slug and reads that
 * store's live keys. The API log then writes the response body to disk (P1-OWN-03's writer).
 *
 * THE ORACLE is the response body: the leak IS what is returned, so the sentinel secret must be
 * absent from it, with a positive control that the endpoint still lists the gateway (otherwise
 * "secret absent" would pass by returning nothing). PUBLISHABLE keys and the gateway's name,
 * image and enabled flag are the legitimate payload and must survive.
 */
class PaymentListSecretDisclosureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'sk_live_FIXTURE_SENTINEL_9d41';
    private const STRIPE_PUBLISHABLE = 'pk_live_PUBLIC_77aa';
    private const PAYSTACK_SECRET = 'sk_paystack_FIXTURE_SENTINEL_31c2';

    /**
     * EVERY field name the endpoint can return, classified BY A HUMAN (harvested from the live response
     * of this tree, 129 names). SECRET = must come back null; KEEP = the checkout's legitimate payload
     * (display fields, publishable/public keys, client/merchant/store IDENTIFIERS, modes).
     * A gateway added later puts a new name in neither list and fails `test_every_field_...` below until
     * someone decides which it is -- that is the point.
     */
    private const SECRET_FIELDS = [
        // Setting::paymentList's entry 52 only: sent as `Authorization: Bearer <username>` (PaymentService.php:394).
        'PAYMERA_USERNAME',
        'Xendit_api_key', 'Xendit_token_key', 'aamarpay_signature_key', 'authorizenet_transaction_key',
        'benefit_private_key', 'benefit_secret_key', 'braintree_pay_private_key', 'cashfree_key',
        'cashfree_secret_key', 'cinet_pay_api_key', 'coingate_auth_token', 'cybersource_pay_api_key',
        'cybersource_pay_secret_key', 'dpo_pay_Company_Token', 'easebuzz_merchant_key', 'easebuzz_salt_key',
        'esewa_merchant_key', 'fedpay_secret_key', 'flutterwave_secret', 'iyzipay_private_key',
        'iyzipay_secret_key', 'khalti_secret_key', 'mercado_access_token', 'midtrans_secret_key',
        'mollie_api_key', 'myfatoorah_pay_api_key', 'nepalste_secret_key', 'nmi_api_private_key',
        'ozow_pay_api_key', 'ozow_pay_private_key', 'paddle_vendor_auth_code', 'payfast_merchant_key',
        'payfast_salt_passphrase', 'payhere_app_secret', 'payhere_merchant_secret',
        'paymentwall_private_key', 'paynow_pay_integration_key', 'paypal_secret', 'paystack_secret',
        'paytabs_server_key', 'paytm_merchant_key', 'paytr_merchant_key', 'paytr_salt_key',
        'payu_merchant_key', 'payu_salt_key', 'phonepe_client_secret', 'powertranz_pay_processing_password',
        'razorpay_secret_key', 'senang_pay_secret_key', 'sofort_secret_key_key',
        'sslcommerz_pay_secret_key', 'stripe_secret_key', 'tap_secret_key', 'telegram_access_token',
        'yookassa_secret_key',
    ];

    private const KEEP_FIELDS = [
        // Setting::paymentList's entry 52 only: an identifier.
        'PAYMERA_TERMINAL_ID',
        // The on/off flag the vendor code mislabels (ApiController:2361,2380); verified by reading, kept so the value is unchanged.
        'secretKey',
        'Senangpay_mode', 'aamarpay_description', 'aamarpay_store_id', 'authorizenet_login_id',
        'authorizenet_mode', 'benefit_mode', 'braintree_mode', 'braintree_pay_merchant_id',
        'braintree_pay_public_key', 'categoryCode', 'cinet_pay_site_id', 'coingate_mode',
        'cybersource_pay_merchant_id', 'detail', 'dpo_pay_Service_Type', 'easebuzz_enviroment_name',
        'esewa_mode_key', 'fedpay_public_key', 'flutterwave_image', 'image', 'iyzipay_mode',
        'khalti_public_key', 'mercado_mode', 'mollie_partner_id', 'mollie_profile_id', 'myfatoorah_mode',
        'myfatoorah_pay_country_iso', 'name', 'name_string', 'nepalste_public_key', 'ozow_mode',
        'ozow_pay_Site_key', 'paddle_mode', 'paddle_public_key', 'paddle_vendor_id',
        'paiementpro_merchant_id', 'payfast_merchant_id', 'payfast_mode', 'payhere_app_id',
        'payhere_merchant_id', 'payhere_mode', 'paymentwall_public_key', 'paynow_mode',
        'paynow_pay_integration_id', 'paynow_pay_merchant_email', 'paypal_client_id', 'paypal_mode',
        'paystack_public_key', 'paytabs_profile_id', 'paytabs_region', 'paytm_industry_type',
        'paytm_merchant_id', 'paytm_mode', 'paytr_merchant_id', 'payu_mode', 'phonepe_client_id',
        'phonepe_client_version', 'phonepe_mode', 'powertranz_mode', 'powertranz_pay_merchant_id',
        'powertranz_pay_production_url', 'public_key', 'razorpay_public_key', 'senang_pay_merchant_id',
        'skrill_email', 'sofort_publishable_key', 'sslcommerz_mode', 'sslcommerz_pay_store_id', 'status',
        'stripe_publishable_key', 'telegram_chat_id', 'whatsapp_number', 'yookassa_shop_id_key',
    ];

    private function victimStore(): Store
    {
        [$merchant, $store] = $this->makeTenant();

        foreach ([
            'is_stripe_enabled' => 'on',
            'publishable_key' => self::STRIPE_PUBLISHABLE,
            'stripe_secret' => self::STRIPE_SECRET,
            'is_paystack_enabled' => 'on',
            'paystack_public_key' => 'pk_paystack_PUBLIC_5e',
            'paystack_secret' => self::PAYSTACK_SECRET,
        ] as $name => $value) {
            Setting::forceCreate(['name' => $name, 'value' => $value, 'store_id' => $store->id, 'created_by' => $merchant->id]);
        }

        return $store;
    }

    private function shopperOfAnotherStoreToken(): string
    {
        [, $other] = $this->makeTenant();

        return Customer::forceCreate([
            'first_name' => 'Other', 'last_name' => 'Shopper', 'email' => 'other@example.test',
            'password' => bcrypt('x'), 'store_id' => $other->id, 'status' => 1,
            'billable_id' => $other->created_by, 'billable_type' => \App\Models\User::class,
        ])->createToken('t')->plainTextToken;
    }

    private function paymentList(Store $store, string $token)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/' . $store->slug . '/payment-list');
    }

    /** POSITIVE CONTROL: the endpoint still lists the gateways and their PUBLIC keys. */
    public function test_the_endpoint_still_lists_the_enabled_gateways_with_their_public_keys(): void
    {
        $store = $this->victimStore();

        $body = (string) $this->paymentList($store, $this->shopperOfAnotherStoreToken())->getContent();

        $this->assertStringContainsString(self::STRIPE_PUBLISHABLE, $body,
            'The checkout needs the publishable key: the fix must not empty the list.');
        $this->assertStringContainsString('stripe', $body);
    }

    /** THE REPRODUCED DEFECT: a shopper of ANOTHER store reads this store's secret keys. */
    public function test_a_shopper_of_another_store_cannot_read_a_stores_gateway_secrets(): void
    {
        $store = $this->victimStore();

        $body = (string) $this->paymentList($store, $this->shopperOfAnotherStoreToken())->getContent();

        $this->assertStringNotContainsString(self::STRIPE_SECRET, $body, 'The Stripe SECRET key was returned to a stranger.');
        $this->assertStringNotContainsString(self::PAYSTACK_SECRET, $body, 'The Paystack SECRET key was returned to a stranger.');
    }

    /** Even the store's OWN shopper has no use for the secret: the server charges, the client never does. */
    public function test_the_stores_own_shopper_does_not_receive_the_secrets_either(): void
    {
        $store = $this->victimStore();
        $token = Customer::forceCreate([
            'first_name' => 'Own', 'last_name' => 'Shopper', 'email' => 'own@example.test',
            'password' => bcrypt('x'), 'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => \App\Models\User::class,
        ])->createToken('t')->plainTextToken;

        $body = (string) $this->paymentList($store, $token)->getContent();

        $this->assertStringContainsString(self::STRIPE_PUBLISHABLE, $body, 'Positive control: the list must still be returned to the store\'s own shopper.');
        $this->assertStringNotContainsString(self::STRIPE_SECRET, $body);
        $this->assertStringNotContainsString(self::PAYSTACK_SECRET, $body);
    }

    /**
     * THE WHOLE CHAIN, ANONYMOUS. `POST /api/{slug}/register` is public (`APILog` only) and returns a
     * Sanctum token, so nobody needs to be a customer of anything: register on ANY store, then ask
     * for the victim's list. This is the measurement that makes the defect critical rather than high.
     */
    public function test_an_anonymous_internet_user_can_not_register_their_way_to_a_stores_gateway_secrets(): void
    {
        $victim = $this->victimStore();
        [, $own] = $this->makeTenant();

        $registered = $this->postJson('/api/' . $own->slug . '/register', [
            'first_name' => 'Anon', 'last_name' => 'Ymous', 'email' => 'anon' . uniqid() . '@example.test',
            'mobile' => '5550001111', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        $token = $registered->json('data.token') ?? $registered->json('token');
        $this->assertNotEmpty($token, 'Fixture premise: public registration must hand back a token, or this chain does not exist. Response: '
            . substr((string) $registered->getContent(), 0, 300));

        $body = (string) $this->paymentList($victim, (string) $token)->getContent();

        $this->assertStringContainsString(self::STRIPE_PUBLISHABLE, $body, 'Positive control: the list must still be returned.');
        $this->assertStringNotContainsString(self::STRIPE_SECRET, $body,
            'An anonymous visitor registered on their own store and read another store\'s Stripe SECRET key.');
    }

    /** THE RATCHET: a field name nobody has classified cannot reach clients. */
    public function test_every_field_the_endpoint_can_return_is_classified_as_secret_or_public(): void
    {
        $store = $this->victimStore();

        $seen = [];
        foreach ($this->paymentList($store, $this->shopperOfAnotherStoreToken())->json('data') as $entry) {
            foreach (array_keys($entry) as $name) {
                $seen[$name] = true;
            }
        }

        // The web checkout's twin builder is a second source of the same names (plus Paymera's entry 52): harvest it
        // too, so the ratchet is true of both (review round 3: it had only ever seen the API response).
        foreach (\App\Models\Setting::paymentList($store->slug) as $entry) {
            foreach (array_keys($entry) as $name) {
                $seen[$name] = true;
            }
        }

        $unclassified = array_values(array_diff(array_keys($seen), self::SECRET_FIELDS, self::KEEP_FIELDS));
        $this->assertSame([], $unclassified,
            'The payment list returned fields nobody has classified: ' . implode(', ', $unclassified)
            . '. Decide whether each is a SECRET (add it to SECRET_FIELDS and make GatewaySecretRedactor catch it) '
            . 'or public (add it to KEEP_FIELDS).');

        $this->assertSame([], array_values(array_intersect(self::SECRET_FIELDS, self::KEEP_FIELDS)), 'A field is in both lists.');
    }

    /** The redactor agrees with the human classification on every name, in both directions. */
    public function test_the_redactor_nulls_exactly_the_secret_fields(): void
    {
        $entry = [];
        foreach (array_merge(self::SECRET_FIELDS, self::KEEP_FIELDS) as $name) {
            // `secretKey` carries the on/off flag in production code (see GatewaySecretRedactor::PUBLIC_FLAG_NAMES).
            $entry[$name] = $name === 'secretKey' ? 'on' : 'VALUE-' . $name;
        }

        $out = \App\Services\GatewaySecretRedactor::redact($entry);

        foreach (self::SECRET_FIELDS as $name) {
            $this->assertNull($out[$name], "'{$name}' is a secret and was returned.");
        }
        foreach (self::KEEP_FIELDS as $name) {
            $this->assertSame($name === 'secretKey' ? 'on' : 'VALUE-' . $name, $out[$name], "'{$name}' is public and was removed: the checkout needs it.");
        }
        $this->assertSame(array_keys($entry), array_keys($out), 'The keys must stay: clients decode this into typed models.');
    }

    /** The redactor is a deny-list by NAME, so the names a gateway might use tomorrow are pinned too. */
    public function test_the_redactor_also_catches_the_names_a_future_gateway_would_use(): void
    {
        $future = ['telegram_bot_token', 'acme_apikey', 'acme_api_key', 'acme_pwd', 'acme_credentials', 'bearer_token', 'ACME_PRIVATE_KEY'];

        $entry = [];
        foreach ($future as $name) {
            $entry[$name] = 'VALUE-' . $name;
        }

        foreach (\App\Services\GatewaySecretRedactor::redact($entry) as $name => $value) {
            $this->assertNull($value, "'{$name}' looks like a credential and was returned.");
        }
    }

    /** The web checkout's twin builder redacts too (defence in depth: its caller renders only display fields today). */
    public function test_the_web_checkout_builder_does_not_carry_secrets_either(): void
    {
        $store = $this->victimStore();

        $payments = \App\Models\Setting::paymentList($store->slug);

        $flat = json_encode($payments);
        $this->assertStringContainsString(self::STRIPE_PUBLISHABLE, $flat, 'Positive control: the builder must still list the gateway.');
        $this->assertStringNotContainsString(self::STRIPE_SECRET, $flat);
        $this->assertStringNotContainsString(self::PAYSTACK_SECRET, $flat);
    }

    /** The `secretKey` exemption is for the on/off flag only: a credential stored under that label is still nulled. */
    public function test_the_secretkey_exemption_is_for_the_flag_and_not_for_a_credential_under_that_label(): void
    {
        $r = fn (array $e) => \App\Services\GatewaySecretRedactor::redact($e);

        $this->assertSame('on', $r(['secretKey' => 'on'])['secretKey']);
        $this->assertSame('off', $r(['secretKey' => 'off'])['secretKey']);
        $this->assertNull($r(['secretKey' => 'abc123-a-real-looking-key'])['secretKey']);
        $this->assertNull($r(['secretKey' => 'sk_live_FIXTURE_notaflag'])['secretKey']);
    }

    /** Paymera's bearer credential rides in the twin builder's entry 52. */
    public function test_the_paymera_bearer_credential_is_nulled_in_the_twin_builder(): void
    {
        $store = $this->victimStore();
        \App\Models\Setting::forceCreate(['name' => 'PAYMERA_USERNAME', 'value' => 'paymera-bearer-FIXTURE-7f31', 'store_id' => $store->id, 'created_by' => $store->created_by]);

        $flat = json_encode(\App\Models\Setting::paymentList($store->slug));

        $this->assertStringContainsString('PAYMERA_TERMINAL_ID', $flat, 'Positive control: the Paymera entry must still be listed.');
        $this->assertStringNotContainsString('paymera-bearer-FIXTURE-7f31', $flat);
    }
}
