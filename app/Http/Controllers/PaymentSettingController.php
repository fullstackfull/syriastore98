<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Utility;
use App\Payments\RetiredGateways;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class PaymentSettingController extends Controller
{
    protected $store_id;
    protected $post = [];

    /** Gateways the caller asked to enable that were left off for want of a required field. */
    protected $incomplete = [];

    /**
     * Process payment settings form submission
     */
    public function update(Request $request)
    {
        session()->put(['setting_tab' => 'payment_setting']);
        // P1-SEC-18 F5. Overwrites every gateway credential of the caller's store, and the route carried no permission check at
        // all: the Settings menu entry is the only thing that hid it from staff. See App\Services\StoreSettingsAccess.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $this->store_id = !empty(getCurrentStore()) ? getCurrentStore() : '1';

        // Handle currency settings
        $this->handleBasicSettings($request);

        // Process all payment gateways
        $gateways = $this->getPaymentGateways();
        
        foreach ($gateways as $gateway) {
            $this->processGateway($request, $gateway);
        }

        // Save all settings at once
        $this->saveSettings();

        /*
         * F142. A gateway the merchant asked to enable but which could not be enabled is
         * reported BY NAME, with the fields it is waiting for. A silent "saved successfully"
         * over a gateway that is still off would be worse than the old behaviour of enabling it
         * broken — at least that failed visibly, at checkout.
         */
        if ($this->incomplete !== []) {
            $names = array_map(function (array $row): string {
                return __($row['gateway']) . ' (' . implode(', ', array_map(
                    fn (string $f): string => __($this->secretLabel($f) ?? $f), $row['fields']
                )) . ')';
            }, $this->incomplete);

            return redirect()->back()->with('error', __(
                'Saved, but these gateways were left switched off because a required value is missing: :list',
                ['list' => implode(' · ', $names)]
            ));
        }

        return redirect()->back()->with('success', __('Setting successfully updated.'));
    }

    /**
     * Handle basic currency settings
     */
    private function handleBasicSettings(Request $request)
    {
        if (isset($request->CURRENCY)) {
            $this->post['CURRENCY'] = $request->CURRENCY;
        }
        if (isset($request->CURRENCY_NAME)) {
            $this->post['CURRENCY_NAME'] = $request->CURRENCY_NAME;
        }
    }

    /**
     * Process a single payment gateway
     */
    private function processGateway(Request $request, $gateway)
    {
        $id = $gateway['id'];
        $enableKey = "is_{$id}_enabled";
        $imageKey = "{$id}_image";
        $infoKey = "{$id}_unfo";
        
        // A RETIRED gateway (RetiredGateways::PROVIDERS) cannot be switched back on from this
        // form. Its enable row is pinned to 'off' and the `== 'on'` branch below is skipped,
        // so an operator who ticks the box and saves changes nothing -- the attempt is
        // INEFFECTIVE, not merely discouraged.
        //
        // NOTHING STORED IS DESTROYED. This method only ever writes the keys it places in
        // $this->post, so returning here leaves every already-stored credential row
        // (payhere_merchant_secret, yookassa_secret_key, the images, the descriptions)
        // exactly as it was. Those rows are the owner's data. Deleting them is not this
        // change's job and is explicitly out of scope.
        if (RetiredGateways::isRetired($id)) {
            $this->post[$enableKey] = 'off';

            return;
        }

        // Set default status to off
        $this->post[$enableKey] = 'off';
        
        // Process only if gateway is enabled
        if ($request->$enableKey == 'on') {
            // Handle image upload if provided
            if (!empty($request->$imageKey)) {
                $this->handleImageUpload($request->$imageKey, $imageKey);
            }
            
            /*
             * F142 — THE VALIDATION BITES ON THE TRANSITION, NOT ON THE EXISTING STATE.
             *
             * It had never run: `validateGatewayFields()`'s return was discarded and its only
             * failure path was a `redirect()` nobody sends, so every `required_fields` entry
             * across 52 gateways was decorative. Switching it on wholesale is what the owner's
             * decision forbids, for a concrete reason: this screen is ONE form and ONE save
             * covering all 52 gateways, so a single gateway enabled today with a field it never
             * filled would make the merchant unable to save ANYTHING here, with no way to tell
             * which gateway was at fault.
             *
             * So the rule applies only when a gateway is being TURNED ON — `$wasEnabled` reads
             * the STORED row, not the request. A gateway already on keeps saving exactly as it
             * does today; one being enabled must be complete. That is "do not accept an
             * incomplete configuration as valid" without breaking a single existing gateway.
             *
             * IMPACT MEASURED, NOT ASSUMED: all 118 `required_fields` entries across the 52
             * gateways have a rendered control (counting the mode radios, which come from
             * `mode_options` rather than from `fields`), so the rule can never demand something
             * the merchant has no way to supply.
             *
             * AND A FAILURE DOES NOT LOSE THE SAVE. The gateway is left OFF and named in the
             * message; everything the merchant typed is still written, and every other gateway
             * still saves. Aborting the whole form would punish 51 gateways for one.
             */
            $wasEnabled = ($this->storedValue($enableKey) ?? 'off') === 'on';
            $missing = $wasEnabled ? [] : $this->missingRequiredFields($request, $gateway);

            if ($missing !== []) {
                $this->incomplete[] = ['gateway' => $gateway['id'], 'fields' => $missing];

                // An incomplete gateway is not a valid one. The typed values below are still
                // stored, so the merchant's progress survives.
                $this->post[$enableKey] = 'off';
            } else {
                $this->post[$enableKey] = 'on';
            }
            
            // Store gateway fields.
            //
            // F117. This was `!empty($request->$field) ? $request->$field : ''` -- a field that
            // posts nothing wrote the EMPTY STRING over whatever was stored. That was harmless
            // only while every field rendered its value back, so "nothing posted" could not
            // happen for a configured gateway. The settings screen now MASKS the 58 secret
            // fields, so a save of anything else on this screen posts them empty, and this line
            // would have wiped every gateway credential the merchant had.
            //
            // `SecretSetting::resolve()` keeps the stored value when nothing was typed. It is
            // applied to the secret fields only: a public field (a merchant id, a mode, a
            // description) is still rendered with its value, so an empty post there really does
            // mean the merchant cleared it.
            $secretFields = $this->secretFieldsFor();

            foreach ($gateway['fields'] as $field) {
                /*
                 * THE ENABLE KEY IS IN THIS LIST, and it is decided ABOVE, not here. Every
                 * gateway's `fields` begins with its own `is_<id>_enabled`, so without this the
                 * loop writes `$request->is_stripe_enabled` straight over the value the F142
                 * branch just set — which is exactly how the first version of that fix reported
                 * "left switched off" in the message while storing 'on'. Measured, not
                 * reasoned: the probe showed `is_stripe_enabled = 'on'` beside an error naming
                 * both missing fields.
                 */
                if ($field === $enableKey) {
                    continue;
                }

                if (in_array($field, $secretFields, true)) {
                    $this->post[$field] = \App\Support\SecretSetting::resolve(
                        $request->$field,
                        $this->storedValue($field),
                    );

                    continue;
                }

                /*
                 * F143 — A FIELD THAT IS NOT IN THE REQUEST IS LEFT ALONE, rather than written
                 * as ''. The old line could not tell "the merchant cleared this box" from "this
                 * control was never on the page", and the difference is a real one:
                 * `paiementpro_mode` is in that gateway's save list while the display array
                 * gives it no `mode_options`, so no radio is ever rendered, nothing posts it,
                 * and every save that enabled the gateway wrote '' over the stored mode.
                 *
                 * `has()` is the distinction the old code lacked: PRESENT-and-empty still
                 * clears (a GET form submits every control, so that is how a text field is
                 * emptied), ABSENT keeps. No mode options were invented and no collection logic
                 * touched — what PaiementPro's modes are is a question about that provider's
                 * contract. Owner decision F143.
                 */
                if (! $request->has($field)) {
                    continue;
                }

                $this->post[$field] = !empty($request->$field) ? $request->$field : '';
            }
            
            // Store description/info
            if (isset($request->$infoKey)) {
                $this->post[$infoKey] = $request->$infoKey;
            }
        }
    }

    /**
     * Handle image upload for payment gateway
     */
    private function handleImageUpload($image, $imageKey)
    {
        $uploadResult = upload_theme_image($image);
        
        if ($uploadResult['status'] == false) {
            return redirect()->back()->with('error', $uploadResult['message']);
        }
        
        $where = ['name' => $imageKey, 'store_id' => $this->store_id];
        $setting = Setting::where($where)->first();

        if (!empty($setting) && !empty($setting->value) && File::exists(base_path($setting->value))) {
            Utility::changeStorageLimit(\Auth::user()->creatorId(), $setting->value);
        }
        
        $this->post[$imageKey] = $uploadResult['image_path'];
    }

    /**
     * The required fields this gateway is missing.
     *
     * A PREDICATE THAT RETURNS, which is the whole repair. The method this replaces built a
     * validator and then did `return redirect()->back()->with('error', …)` from a private method
     * whose caller discarded the value — so a failure constructed a response object nobody sent
     * and the save carried on. MEASURED before the fix: a POST enabling Stripe with both its
     * required keys empty and nothing stored wrote `is_stripe_enabled = 'on'`.
     *
     * A SECRET THAT IS ALREADY STORED COUNTS AS SUPPLIED. Its box is masked and posts empty when
     * the merchant leaves it alone, so demanding a value would mean retyping a Stripe secret key
     * in order to change a gateway's description.
     */
    private function missingRequiredFields(Request $request, array $gateway): array
    {
        $secrets = $this->secretFieldsFor();
        $missing = [];

        foreach ($gateway['required_fields'] ?? [] as $field) {
            // The enable flag appears in some gateways' required list; it is the thing being
            // set, not a field the merchant fills.
            if (str_starts_with($field, 'is_')) {
                continue;
            }

            if (filled($request->input($field))) {
                continue;
            }

            if (in_array($field, $secrets, true)
                && \App\Support\SecretSetting::isSet($this->storedValue($field))) {
                continue;
            }

            $missing[] = $field;
        }

        return $missing;
    }

    /**
     * F141 — step one of removing a stored gateway secret: ask, on the server.
     *
     * TWO POSTS, AND THE SECOND ONE IS NOT A FORMALITY. The owner's decision requires an
     * explicit action, an appropriate permission and a CLEAR CONFIRMATION, and a confirmation
     * that lives in JavaScript is not one for an action that destroys a credential:
     *
     *   - the house helper `show_confirm` (`public/js/custom.js:107`) resolves its form with
     *     `closest("form")`, and the payment screen is ONE form wrapping all 52 gateways, so a
     *     button inside a gateway card would submit the whole settings form instead;
     *   - and it FAILS OPEN. With JavaScript broken or blocked the button still submits, and the
     *     secret is gone with no question asked. A destructive action has to fail CLOSED.
     *
     * So the confirmation is a server round trip: this action renders a page naming the gateway,
     * naming the field, and saying what removal does and does not do. Nothing is written here.
     *
     * NOT A GET, deliberately. P2-05-1 removed four state-changing GETs from this codebase —
     * two of them unauthorised cross-tenant writes — and `StateChangingGetInventoryTest`
     * ratchets the set so a fifth cannot appear quietly.
     */
    public function confirmSecretRemoval(Request $request)
    {
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->store_id = !empty(getCurrentStore()) ? getCurrentStore() : '1';

        $field = (string) $request->input('field', '');
        $gateway = $this->gatewayOwningSecret($field);

        if ($gateway === null) {
            return redirect()->back()->with('error', __('Unknown payment credential.'));
        }

        if (! \App\Support\SecretSetting::isSet($this->storedValue($field))) {
            return redirect()->back()->with('error', __('There is no saved value to remove.'));
        }

        return view('setting.payment_partials.confirm_secret_removal', [
            'field' => $field,
            'label' => $this->secretLabel($field) ?? $field,
            'gateway' => $gateway,
            'blocked' => $this->removalBlockedBecauseEnabled($gateway, $field),
        ]);
    }

    /**
     * F141 — step two: remove ONE stored credential, for ONE gateway, in ONE store.
     *
     * WHAT THIS DELIBERATELY DOES NOT CHANGE, because the owner's decision says so explicitly
     * and because both behaviours are load-bearing elsewhere:
     *
     *   - switching a gateway OFF still leaves its credentials alone (`processGateway()` writes
     *     nothing when the gateway is not enabled), so a merchant can disable and re-enable
     *     without retyping;
     *   - an EMPTY BOX on a normal save still means "keep", which is the contract
     *     `SecretSetting::resolve()` and every masked field on this screen depend on.
     *
     * Removal is therefore the only way a credential leaves, and it is never a side effect.
     *
     * A REQUIRED SECRET CANNOT BE REMOVED FROM AN ENABLED GATEWAY. Doing so would leave the
     * gateway switched on and unable to authenticate — a checkout that fails at the payment
     * step, which is the worst place to discover a configuration change. The merchant is told to
     * disable the gateway first; that is a deliberate, visible act with its own consequence,
     * rather than this action silently taking a gateway offline on their behalf.
     */
    public function removeSecret(Request $request)
    {
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->store_id = !empty(getCurrentStore()) ? getCurrentStore() : '1';

        $field = (string) $request->input('field', '');
        $gateway = $this->gatewayOwningSecret($field);

        if ($gateway === null) {
            return redirect()->back()->with('error', __('Unknown payment credential.'));
        }

        /* The confirmation is re-checked on the WRITE, not only on the page that offered it. A
           page can be skipped; a request cannot skip the handler that acts on it. */
        if ($request->input('confirm') !== 'remove') {
            return redirect()->back()->with('error', __('The removal was not confirmed.'));
        }

        if ($this->removalBlockedBecauseEnabled($gateway, $field)) {
            return redirect()->back()->with('error', __(
                'Turn :gateway off before removing this credential, so it cannot be left switched on without one.',
                ['gateway' => __($gateway['name'])]
            ));
        }

        /* ONE ROW, IN THIS STORE. Not `where('name', $field)` alone -- `Setting` is in no
           `scoped_models` entry, so it adds no predicate of its own and this `store_id` is the
           only thing confining the delete. The same predicate the write uses. */
        Setting::where('name', $field)->where('store_id', $this->store_id)->delete();

        /* `Setting` is `Cachable`, and the delete above goes through Eloquent so the model cache
           is flushed with it -- but `getAdminAllSetting()` is read through `Cache::remember`
           elsewhere on this screen, so the admin bag is dropped too or the field would still
           report "a value is saved" on the next render. */
        Cache::forget('admin_details');

        return redirect()->route('setting.index')->with('success', __(
            'The saved :field for :gateway has been removed from Store Syria. This does NOT revoke the credential with the payment provider — do that in their dashboard as well.',
            ['field' => __($this->secretLabel($field) ?? $field), 'gateway' => __($gateway['name'])]
        ));
    }

    /**
     * The gateway whose field this is, or null when the name is not a SECRET field of any
     * gateway. An allowlist by construction: a name that is not in `paymentSettingArray()` with
     * `'secret' => true` can never reach the delete, so the parameter cannot be pointed at
     * `site_currency`, a public key, or another tenant's row name.
     */
    private function gatewayOwningSecret(string $field): ?array
    {
        if ($field === '') {
            return null;
        }

        $method = new \ReflectionMethod(SettingController::class, 'paymentSettingArray');
        $method->setAccessible(true);

        foreach ($method->invoke(app(SettingController::class)) as $definition) {
            foreach ($definition['fields'] ?? [] as $candidate) {
                if ($candidate['key'] === $field && ($candidate['secret'] ?? true)) {
                    return $definition;
                }
            }
        }

        return null;
    }

    /**
     * The field's own label, for a message a merchant can act on.
     *
     * Across ALL display fields, not only the secret ones: F142 reports missing REQUIRED fields
     * and most of those are public (a merchant id, a site code). Resolving only secrets would
     * have printed raw column names like `paytm_merchant_id` at a merchant.
     */
    private function secretLabel(string $field): ?string
    {
        $method = new \ReflectionMethod(SettingController::class, 'paymentSettingArray');
        $method->setAccessible(true);

        foreach ($method->invoke(app(SettingController::class)) as $definition) {
            foreach ($definition['fields'] ?? [] as $candidate) {
                if ($candidate['key'] === $field) {
                    return $candidate['label'];
                }
            }
        }

        return null;
    }

    /**
     * Whether this removal would leave an ENABLED gateway without a credential it needs.
     *
     * `required_fields` lives on the SAVE-side definition, which uses different ids for the same
     * gateway (`cod` there, `COD` in the display array), so it is matched by FIELD NAME -- the
     * same reason `secretFieldsFor()` does. Field names are globally unique across the 52
     * gateways because they are `settings` rows.
     */
    private function removalBlockedBecauseEnabled(array $gateway, string $field): bool
    {
        if (($this->storedValue($gateway['enable_key']) ?? 'off') !== 'on') {
            return false;
        }

        foreach ($this->getPaymentGateways() as $definition) {
            if (in_array($field, $definition['required_fields'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The field names this gateway treats as secrets, taken from the ONE place that decides:
     * `SettingController::paymentSettingArray()`, whose `'secret'` flag the settings screen also
     * renders from. Reading it here rather than keeping a second list is the point -- this
     * controller already carries its own copy of each gateway's field names, and the two lists
     * use different ids for the same gateway (`cod` here, `COD` there), which is exactly how a
     * second copy of the secret flags would drift.
     *
     * Matched on the FIELD NAME, not the gateway id, for that reason -- which is why this takes
     * no gateway argument at all. Field names are globally unique across the 52 gateways (they
     * are `settings` rows, so they have to be), so a name match needs no id match and cannot be
     * defeated by the casing difference.
     *
     * A field this controller knows and that array does not is treated as NOT secret, which is
     * the pre-existing behaviour for it; `PaymentGatewayFieldContractTest` asserts the two lists
     * agree, so that case is a test failure rather than a silent exposure.
     */
    private function secretFieldsFor(): array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = [];
            $method = new \ReflectionMethod(SettingController::class, 'paymentSettingArray');
            $method->setAccessible(true);

            foreach ($method->invoke(app(SettingController::class)) as $definition) {
                foreach ($definition['fields'] ?? [] as $field) {
                    // Absent means secret, matching the view's `?? true`: a field added without
                    // the flag is protected rather than exposed.
                    if ($field['secret'] ?? true) {
                        $cache[] = $field['key'];
                    }
                }
            }
        }

        return $cache;
    }

    /**
     * The value currently stored for a setting in THIS store.
     *
     * READ WITH THE SAME PREDICATE THE WRITE USES, which is the whole point.
     * `saveSettings()` does `updateOrCreate(['name' => $key, 'store_id' => $this->store_id])`,
     * so this is `where('name')->where('store_id', $this->store_id)` and nothing else: the row
     * read is provably the row written, and no value can cross a store boundary.
     *
     * NOT `getAdminAllSetting()`, which also filters on `created_by = <tenant root>` and
     * resolves its own store from `$user->current_store`. Where those two disagree -- a staff
     * member whose `current_store` has drifted from their employer's -- the screen would show
     * one store's settings while the save wrote another's, and a "keep what is stored" read
     * taken from the screen's copy would write store B's secret into store A's row.
     *
     * (An earlier version of this comment justified the direct query as avoiding the model
     * cache. That was wrong and is corrected rather than left standing: `Setting` is `Cachable`,
     * so `Setting::where(...)->first()` goes through the cached builder exactly like any other
     * Eloquent read. The reason is the predicate, not the cache.)
     */
    private function storedValue(string $name): ?string
    {
        $row = Setting::where('name', $name)->where('store_id', $this->store_id)->first();

        return $row?->value;
    }

    /**
     * Save all settings to database
     */
    private function saveSettings()
    {
        $settingQuery = Setting::query();
        
        foreach ($this->post as $key => $value) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id' => $this->store_id
                ],
                [
                    'value' => $value,
                    'name' => $key,
                    'store_id' => $this->store_id,
                    'created_by' => auth()->user()->id,
                ]
            );
        }
    }

    /**
     * Get configuration for all payment gateways
     */
    public function getPaymentGateways()
    {
        return [
            [
                'id' => 'cod',
                'fields' => ['is_cod_enabled', 'cod_info'],
                'required_fields' => []
            ],
            [
                'id' => 'bank_transfer',
                'fields' => ['is_bank_transfer_enabled', 'bank_transfer'],
                'required_fields' => []
            ],
            [
                'id' => 'stripe',
                'fields' => ['is_stripe_enabled', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_unfo'],
                'required_fields' => ['stripe_publishable_key', 'stripe_secret_key']
            ],
     [
                'id' => 'Paymera',
                'fields' => ['is_Paymera_enabled', 'PAYMERA_TERMINAL_ID', 'PAYMERA_USERNAME', 'Paymera_unfo'],
                'required_fields' => ['PAYMERA_TERMINAL_ID', 'PAYMERA_USERNAME']
            ],

            [
                'id' => 'paystack',
                'fields' => ['is_paystack_enabled', 'paystack_public_key', 'paystack_secret_key', 'paystack_unfo'],
                'required_fields' => ['paystack_public_key', 'paystack_secret_key']
            ],
            [
                'id' => 'razorpay',
                'fields' => ['is_razorpay_enabled', 'razorpay_public_key', 'razorpay_secret_key', 'razorpay_unfo'],
                'required_fields' => ['razorpay_public_key', 'razorpay_secret_key']
            ],
            [
                'id' => 'mercado',
                'fields' => ['is_mercado_enabled', 'mercado_access_token', 'mercado_mode', 'mercado_unfo'],
                'required_fields' => ['mercado_access_token', 'mercado_mode']
            ],
            [
                'id' => 'skrill',
                'fields' => ['is_skrill_enabled', 'skrill_email', 'skrill_unfo'],
                'required_fields' => ['skrill_email']
            ],
            [
                'id' => 'paymentwall',
                'fields' => ['is_paymentwall_enabled', 'paymentwall_public_key', 'paymentwall_private_key', 'paymentwall_unfo'],
                'required_fields' => ['paymentwall_public_key', 'paymentwall_private_key']
            ],
            [
                'id' => 'paypal',
                'fields' => ['is_paypal_enabled', 'paypal_client_id', 'paypal_secret_key', 'paypal_mode', 'paypal_unfo'],
                'required_fields' => ['paypal_client_id', 'paypal_secret_key', 'paypal_mode']
            ],
            [
                'id' => 'flutterwave',
                'fields' => ['is_flutterwave_enabled', 'flutterwave_public_key', 'flutterwave_secret_key', 'flutterwave_unfo'],
                'required_fields' => ['flutterwave_public_key', 'flutterwave_secret_key']
            ],
            [
                'id' => 'paytm',
                'fields' => ['is_paytm_enabled', 'paytm_merchant_id', 'paytm_merchant_key', 'paytm_industry_type', 'paytm_mode', 'paytm_unfo'],
                'required_fields' => ['paytm_merchant_id', 'paytm_merchant_key', 'paytm_industry_type', 'paytm_mode']
            ],
            [
                'id' => 'mollie',
                'fields' => ['is_mollie_enabled', 'mollie_api_key', 'mollie_profile_id', 'mollie_partner_id', 'mollie_unfo'],
                'required_fields' => ['mollie_api_key', 'mollie_profile_id', 'mollie_partner_id']
            ],
            [
                'id' => 'coingate',
                'fields' => ['is_coingate_enabled', 'coingate_auth_token', 'coingate_mode', 'coingate_unfo'],
                'required_fields' => ['coingate_auth_token', 'coingate_mode']
            ],
            [
                'id' => 'sspay',
                'fields' => ['is_sspay_enabled', 'sspay_secret_key', 'sspay_category_code', 'sspay_unfo'],
                'required_fields' => ['sspay_secret_key', 'sspay_category_code']
            ],
            [
                'id' => 'toyyibpay',
                'fields' => ['is_toyyibpay_enabled', 'toyyibpay_secret_key', 'toyyibpay_category_code', 'toyyibpay_unfo'],
                'required_fields' => ['toyyibpay_secret_key', 'toyyibpay_category_code']
            ],
            [
                'id' => 'paytabs',
                'fields' => ['is_paytabs_enabled', 'paytabs_profile_id', 'paytabs_server_key', 'paytabs_region', 'paytabs_unfo'],
                'required_fields' => ['paytabs_profile_id', 'paytabs_server_key', 'paytabs_region']
            ],
            [
                'id' => 'iyzipay',
                'fields' => ['is_iyzipay_enabled', 'iyzipay_private_key', 'iyzipay_secret_key', 'iyzipay_mode', 'iyzipay_unfo'],
                'required_fields' => ['iyzipay_private_key', 'iyzipay_secret_key', 'iyzipay_mode']
            ],
            [
                'id' => 'payfast',
                'fields' => ['is_payfast_enabled', 'payfast_merchant_id', 'payfast_merchant_key', 'payfast_salt_passphrase', 'payfast_mode', 'payfast_unfo'],
                'required_fields' => ['payfast_merchant_id', 'payfast_merchant_key', 'payfast_salt_passphrase', 'payfast_mode']
            ],
            [
                'id' => 'benefit',
                'fields' => ['is_benefit_enabled', 'benefit_secret_key', 'benefit_private_key', 'benefit_unfo'],
                'required_fields' => ['benefit_secret_key', 'benefit_private_key']
            ],
            [
                'id' => 'cashfree',
                'fields' => ['is_cashfree_enabled', 'cashfree_key', 'cashfree_secret_key', 'cashfree_unfo'],
                'required_fields' => ['cashfree_key', 'cashfree_secret_key']
            ],
            [
                'id' => 'aamarpay',
                'fields' => ['is_aamarpay_enabled', 'aamarpay_store_id', 'aamarpay_signature_key', 'aamarpay_description', 'aamarpay_unfo'],
                'required_fields' => ['aamarpay_store_id', 'aamarpay_signature_key', 'aamarpay_description']
            ],
            [
                'id' => 'telegram',
                'fields' => ['is_telegram_enabled', 'telegram_access_token', 'telegram_chat_id', 'telegram_unfo'],
                'required_fields' => ['telegram_access_token', 'telegram_chat_id']
            ],
            [
                'id' => 'whatsapp',
                'fields' => ['is_whatsapp_enabled', 'whatsapp_number', 'whatsapp_unfo'],
                'required_fields' => ['whatsapp_number']
            ],
            [
                'id' => 'paytr',
                'fields' => ['is_paytr_enabled', 'paytr_merchant_id', 'paytr_merchant_key', 'paytr_salt_key', 'paytr_unfo'],
                'required_fields' => ['paytr_merchant_id', 'paytr_merchant_key', 'paytr_salt_key']
            ],
            // RETIRED (RetiredGateways::PROVIDERS). The entry is KEPT so the stored
            // credentials keep a home and the admin block is not deleted; processGateway()
            // above never reaches the fields below for a retired gateway.
            [
                'id' => 'yookassa',
                'fields' => ['is_yookassa_enabled', 'yookassa_shop_id_key', 'yookassa_secret_key', 'yookassa_unfo'],
                'required_fields' => ['yookassa_shop_id_key', 'yookassa_secret_key']
            ],
            [
                'id' => 'Xendit',
                'fields' => ['is_Xendit_enabled', 'Xendit_api_key', 'Xendit_token_key', 'Xendit_unfo'],
                'required_fields' => ['Xendit_api_key', 'Xendit_token_key']
            ],
            [
                'id' => 'midtrans',
                'fields' => ['is_midtrans_enabled', 'midtrans_secret_key', 'midtrans_unfo'],
                'required_fields' => ['midtrans_secret_key']
            ],
            [
                'id' => 'nepalste',
                'fields' => ['is_nepalste_enabled', 'nepalste_public_key', 'nepalste_secret_key', 'nepalste_mode', 'nepalste_unfo'],
                'required_fields' => ['nepalste_public_key', 'nepalste_secret_key', 'nepalste_mode']
            ],
            // RETIRED (RetiredGateways::PROVIDERS). Entry KEPT -- see the note on 'yookassa'.
            [
                'id' => 'payhere',
                'fields' => ['is_payhere_enabled', 'payhere_merchant_id', 'payhere_merchant_secret', 'payhere_app_id', 'payhere_app_secret', 'payhere_mode', 'payhere_unfo'],
                'required_fields' => ['payhere_merchant_id', 'payhere_merchant_secret', 'payhere_app_id', 'payhere_app_secret', 'payhere_mode']
            ],
            [
                'id' => 'khalti',
                'fields' => ['is_khalti_enabled', 'khalti_public_key', 'khalti_secret_key', 'khalti_unfo'],
                'required_fields' => ['khalti_public_key', 'khalti_secret_key']
            ],
            [
                'id' => 'authorizenet',
                'fields' => ['is_authorizenet_enabled', 'authorizenet_login_id', 'authorizenet_transaction_key', 'authorizenet_mode', 'authorizenet_unfo'],
                'required_fields' => ['authorizenet_login_id', 'authorizenet_transaction_key', 'authorizenet_mode']
            ],
            [
                'id' => 'tap',
                'fields' => ['is_tap_enabled', 'tap_secret_key', 'tap_unfo'],
                'required_fields' => ['tap_secret_key']
            ],
            [
                'id' => 'phonepe',
                'fields' => ['is_phonepe_enabled', 'phonepe_client_id', 'phonepe_client_secret', 'phonepe_client_version', 'phonepe_mode', 'phonepe_unfo'],
                'required_fields' => ['phonepe_client_id', 'phonepe_client_secret', 'phonepe_client_version', 'phonepe_mode']
            ],
            [
                'id' => 'paddle',
                'fields' => ['is_paddle_enabled', 'paddle_vendor_id', 'paddle_vendor_auth_code', 'paddle_public_key', 'paddle_mode', 'paddle_unfo'],
                'required_fields' => ['paddle_vendor_id', 'paddle_vendor_auth_code', 'paddle_public_key', 'paddle_mode']
            ],
            [
                'id' => 'paiementpro',
                'fields' => ['is_paiementpro_enabled', 'paiementpro_merchant_id', 'paiementpro_mode', 'paiementpro_unfo'],
                'required_fields' => ['paiementpro_merchant_id']
            ],
            [
                'id' => 'fedpay',
                'fields' => ['is_fedpay_enabled', 'fedpay_public_key', 'fedpay_secret_key', 'fedpay_mode', 'fedpay_unfo'],
                'required_fields' => ['fedpay_public_key', 'fedpay_secret_key', 'fedpay_mode']
            ],
            [
                'id' => 'cinetpay',
                'fields' => ['is_cinetpay_enabled', 'cinet_pay_site_id', 'cinet_pay_api_key', 'cinet_pay_secret_key', 'cinet_pay_unfo'],
                'required_fields' => ['cinet_pay_site_id', 'cinet_pay_api_key', 'cinet_pay_secret_key']
            ],
            [
                'id' => 'Senangpay',
                'fields' => ['is_Senangpay_enabled', 'senang_pay_merchant_id', 'senang_pay_secret_key', 'Senangpay_mode', 'senang_pay_unfo'],
                'required_fields' => ['senang_pay_merchant_id', 'senang_pay_secret_key', 'Senangpay_mode']
            ],
            [
                'id' => 'cybersource',
                'fields' => ['is_cybersource_enabled', 'cybersource_pay_merchant_id', 'cybersource_pay_secret_key', 'cybersource_pay_api_key', 'cybersource_pay_unfo'],
                'required_fields' => ['cybersource_pay_merchant_id', 'cybersource_pay_secret_key', 'cybersource_pay_api_key']
            ],
            [
                'id' => 'ozow',
                'fields' => ['is_ozow_enabled', 'ozow_pay_Site_key', 'ozow_pay_private_key', 'ozow_pay_api_key', 'ozow_mode', 'ozow_pay_unfo'],
                'required_fields' => ['ozow_pay_Site_key', 'ozow_pay_private_key', 'ozow_pay_api_key', 'ozow_mode']
            ],
            [
                'id' => 'myfatoorah',
                'fields' => ['is_myfatoorah_enabled', 'myfatoorah_pay_country_iso', 'myfatoorah_pay_api_key', 'myfatoorah_mode', 'myfatoorah_pay_unfo'],
                'required_fields' => ['myfatoorah_pay_country_iso', 'myfatoorah_pay_api_key', 'myfatoorah_mode']
            ],
            [
                'id' => 'easebuzz',
                'fields' => ['is_easebuzz_enabled', 'easebuzz_merchant_key', 'easebuzz_salt_key', 'easebuzz_enviroment_name', 'easebuzz_unfo'],
                'required_fields' => ['easebuzz_merchant_key', 'easebuzz_salt_key', 'easebuzz_enviroment_name']
            ],
            [
                'id' => 'nmi',
                'fields' => ['is_nmi_enabled', 'nmi_api_private_key', 'nmi_unfo'],
                'required_fields' => ['nmi_api_private_key']
            ],
            [
                'id' => 'payu',
                'fields' => ['is_payu_enabled', 'payu_merchant_key', 'payu_salt_key', 'payu_mode', 'payu_unfo'],
                'required_fields' => ['payu_merchant_key', 'payu_salt_key', 'payu_mode']
            ],
            [
                'id' => 'paynow',
                'fields' => ['is_paynow_enabled', 'paynow_pay_integration_id', 'paynow_pay_integration_key', 'paynow_pay_merchant_email', 'paynow_mode', 'paynow_pay_unfo'],
                'required_fields' => ['paynow_pay_integration_id', 'paynow_pay_integration_key', 'paynow_pay_merchant_email', 'paynow_mode']
            ],
            [
                'id' => 'sofort',
                'fields' => ['is_sofort_enabled', 'sofort_publishable_key', 'sofort_secret_key', 'sofort_unfo'],
                'required_fields' => ['sofort_publishable_key', 'sofort_secret_key']
            ],
            [
                'id' => 'esewa',
                'fields' => ['is_esewa_enabled', 'esewa_merchant_key', 'esewa_mode', 'esewa_unfo'],
                'required_fields' => ['esewa_merchant_key', 'esewa_mode']
            ],
            [
                'id' => 'dpopay',
                'fields' => ['is_dpopay_enabled', 'dpo_pay_Company_Token', 'dpo_pay_Service_Type', 'dpo_pay_unfo'],
                'required_fields' => ['dpo_pay_Company_Token', 'dpo_pay_Service_Type']
            ],
            [
                'id' => 'braintree',
                'fields' => ['is_braintree_enabled', 'braintree_pay_merchant_id', 'braintree_pay_public_key', 'braintree_pay_private_key', 'braintree_mode', 'braintree_pay_unfo'],
                'required_fields' => []
            ],
            [
                'id' => 'powertranz',
                'fields' => ['is_powertranz_enabled', 'powertranz_pay_merchant_id', 'powertranz_pay_processing_password', 'powertranz_pay_production_url', 'powertranz_mode', 'powertranz_pay_unfo'],
                'required_fields' => []
            ],
            [
                'id' => 'sslcommerz',
                'fields' => ['is_sslcommerz_enabled', 'sslcommerz_pay_store_id', 'sslcommerz_pay_secret_key', 'sslcommerz_mode', 'sslcommerz_pay_unfo'],
                'required_fields' => []
            ]
        ];
    }
}