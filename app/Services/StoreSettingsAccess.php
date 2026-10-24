<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * May this user read or change a store's gateway, SMTP and integration settings?  P1-SEC-18 F5.
 *
 * One decision point, used by the admin API's secret-bearing handlers (Api\DashboardController::EmailSetting, GetEmailSetting,
 * StripePayment, GetStripePayment, TestMail) and by their web twins (SettingController::settingForm, getEmailSettingFields,
 * saveEmailSettings, PaymentSettingController::update), so the two doors cannot drift apart.
 *
 * WHAT IT ASKS, IN THIS ORDER
 *
 *  1. The platform operator (`users.type` is 'super admin'). First, because Laratrust here has no super-admin bypass
 *     (`permissions_as_gates` is off): `isAbleTo()` alone REFUSES the operator. The comparison is the normalised one every
 *     other operator check uses (PlatformOperatorOnly, RolePolicy, resolveOwnedStore): for the canonical spelling limb 2 admits
 *     the operator as well, because creatorId() returns a 'super admin' their own id, but creatorId() compares EXACTLY, so a
 *     non-canonical spelling (' Super Admin ') is the operator here and everywhere else and only this limb says so -- pinned by
 *     AdminApiSettingsSecretsTest. (A tenant cannot set such a type: the reserved-name rules normalise the same way.)
 *  2. The tenant OWNER: `creatorId() === id`. creatorId() returns the user's own id for exactly the types `admin` and
 *     `super admin`, and `created_by` for everyone else, so this is true only for a merchant (staff resolve to their
 *     employer). It is deliberately the application's own definition of "root of a tenant" and not a re-implemented string
 *     comparison: a staff row whose type merely NORMALISES to 'admin' ('Admin', ' admin') is a staff member for creatorId() and
 *     for this gate. An owner is entitled to their own store's credentials whatever their role rows say, so a merchant whose
 *     `admin` role was never seeded with the permission is not locked out of the shipped app.
 *  3. `isAbleTo('Manage Setting')` -- the permission behind the "Settings" menu entry (CompanyMenuListener:954-965, route
 *     setting.index), where the email and payment tabs live. NOT `Manage Store Setting`: that belongs to the separate "Store
 *     Setting" entry (route app-setting.index), and a role holding only it must not be handed the SMTP password.
 *
 * The question this exists for is STAFF: resolveOwnedStore() and getCurrentStore() resolve a staff member to their employer,
 * so an ownership check admits them whatever role the merchant gave them.
 */
final class StoreSettingsAccess
{
    public const PERMISSION = 'Manage Setting';

    /** The permission behind the separate "Store Setting" menu entry (route app-setting.index), which AppSettingController::index() asks too. */
    public const STORE_PERMISSION = 'Manage Store Setting';

    /**
     * The names ThemeSettings turns into a hosting-panel call. Its radio posts a TOKEN (`enable_storelink` | `enable_domain` |
     * `enable_subdomain`) under the name `enable_domain`, which the handler rewrites into three on/off flags, so a by-name comparison
     * of the posted value with the stored one compares two vocabularies; that handler uses domainRoutingHeld() instead.
     */
    public const DOMAIN_ROUTING = ['domains', 'enable_domain', 'subdomain', 'enable_subdomain', 'enable_storelink'];

    /** Keys that ride along in a form post and are not settings. `_token` would otherwise be called a secret ("token"). */
    private const NOT_SETTINGS = ['_token', '_method'];

    /**
     * Setting names whose WRITE is a payment-, mail-, storage-, integration- or domain-configuration decision (P1-SEC-27). The three
     * Store Setting handlers (AppSettingController::ThemeSettings, seoSettings, shippingLabelSettings) take EVERY posted name/value into
     * `settings` for the caller's store and are open to anyone who holds `Manage Store Setting`; a user who holds only that must not be
     * able to write `MAIL_PASSWORD`, `stripe_secret_key` or `bank_transfer` by name. Those handlers refuse a restricted name unless
     * permits(). (The six Settings-screen handlers need no such rule: they require permits() outright.)
     *
     * By name, lower-cased, and deliberately wide at the edges that matter: anything the secret classifier already calls a secret
     * (GatewaySecretRedactor), every `mail_*`, `s3_*`, `wasabi_*` and `local_storage_*` (where uploads go), every field of every gateway
     * in PaymentSettingController::getPaymentGateways() plus each gateway's tile image (the names Setting::paymentList reads), the
     * admin API's Stripe/COD/bank names, the store currency and the loyalty reward (what a shopper is charged and what a point is worth),
     * the integrations' endpoints and identifiers (`shopify_*`, `woocommerce_*`, `twilio_*`, `whatsapp_*`, `firebase_*`, `fcm_*`, the social-login audience pins that decide whose ID token the storefront accepts: a
     * stored token is sent to whatever URL is stored next to it), and the names the host lookup routes a request by (a domain is a
     * hosting-panel call and decides which store answers a host).
     *
     * NOT in here, on purpose: the script-bearing names (`storejs`, `storecss`, `google_analytic`, `fbpixel_code`) that the Store Setting
     * screen exists to edit. Who may put script on a storefront is the owner's decision (P1-OWN-12).
     */
    private const RESTRICTED_EXACT = [
        'stripe_secret', 'publishable_key', 'stripe_unfo', 'is_stripe_enabled', 'stripe_image',
        'bank_transfer', 'is_bank_transfer_enabled', 'bank_transfer_image',
        'is_cod_enabled', 'cod_info', 'cod_image', 'benefit_mode',
        'currency', 'currency_name', 'defult_currancy', 'defult_currancy_symbol', 'site_currency_symbol_position',
        'loyality_program_enabled', 'reward_point',
        'google_api_audiences', 'google_client_id', 'apple_client_ids', 'facebook_client_id',
        'domains', 'enable_domain', 'subdomain', 'enable_subdomain', 'enable_storelink',
        'storage_setting',
    ];

    private const RESTRICTED_PREFIX = '/^(mail_|s3_|wasabi_|local_storage_|shopify_|woocommerce_|twilio_|whatsapp_|firebase_|fcm_)/';

    /** The payment tile images Setting::paymentList reads (`GetValueByName('<x>_image')`), whose names are not all the gateway's id. */
    private const RESTRICTED_IMAGE = '/^(aamarpay|authorizenet|benefit|cashfree|cinet_pay|coingate|cybersource_pay|esewa|fedpay|flutterwave|iyzipay|khalti|mercado|midtrans|mollie|nepalste|ozow_pay|paddle|paiementpro|payfast|payhere|paymentwall|paymera|paypal|paystack|paytabs|paytm|paytr|phonepe|razorpay|senang_pay|skrill|sofort|sspay|stripe|tap|telegram|toyyibpay|whatsapp|yookassa|cod|bank_transfer)_image$/';

    /**
     * What a reader sees when there is NO row: Utility::Setting() fills these in. A missing row therefore is not "empty" for them, and
     * posting an empty value would turn the method off.
     */
    private const ABSENT_MEANS = ['is_cod_enabled' => 'on', 'is_bank_transfer_enabled' => 'on'];

    /**
     * The spelling MySQL cannot tell apart: the connection collation (utf8mb4_unicode_ci, config/database.php) is case- and
     * accent-insensitive and PAD SPACE, so `Domains`, `DOMAINS`, `domains ` and `dómains` are all the stored `domains` row -- and the
     * writers' updateOrCreate then REWRITES that row, name included (EXECUTED on MySQL 8.0.46:
     * tests/Support/Security/bin/verify-settings-name-collation-on-mysql.php; SQLite compares bytes, so the suite cannot show it).
     */
    public static function normaliseName(string $name): string
    {
        return strtolower(trim($name));
    }

    /** A name with a non-ASCII or control byte. No form posts one; a collation may fold one onto an ASCII name. */
    private static function isOpaqueName(string $name): bool
    {
        // Only spaces are trimmed: trim()'s default also strips NUL, tab and newline, which are exactly the bytes to refuse.
        return preg_match('/[^\x21-\x7E]/', trim($name, ' ')) === 1;
    }

    public static function isRestrictedName(string $name): bool
    {
        $normal = self::normaliseName($name);

        if (in_array($normal, self::NOT_SETTINGS, true)) {
            return false;
        }

        return GatewaySecretRedactor::isSecret($name)
            || GatewaySecretRedactor::isSecret($normal)
            || preg_match(self::RESTRICTED_PREFIX, $normal) === 1
            || preg_match(self::RESTRICTED_IMAGE, $normal) === 1
            || in_array($normal, self::restrictedNames(), true);
    }

    /**
     * Would this write be refused to this user?  True when the post CHANGES a restricted setting and the user does not hold the
     * Settings permission (fails closed for no user at all).
     *
     * A form that always posts its fields -- the payment form every gateway -- resubmits the values already stored when the user
     * changed something else, and refusing THAT would make a whole tab unusable for staff who hold the screen's own permission. So
     * only a CHANGE is refused: the posted value is compared with the store's stored value (the one a reader sees: the newest row),
     * a missing row reads as what Utility::Setting() would give (ABSENT_MEANS, else empty), a boolean is compared as the database
     * stores it ('1' / '0'), and anything that is not a plain scalar -- an array, an uploaded file -- is a change. The same shape as
     * the owner-identity rule in updateStoreOwnerInfo.
     *
     * Two more refusals that do not depend on the value, because MySQL's collation lets a different SPELLING address the same row
     * (see normaliseName): a restricted name posted in a spelling other than the stored one (it would rename the merchant's row), and
     * a name with a non-ASCII or control byte, or no string name at all (an integer key), at all (it may fold onto a restricted name).
     *
     * @param  array<int|string, mixed>  $post  the request body as it will be written (keys are setting names)
     * @param  int|string|null  $storeId  the store the write lands in (getCurrentStore())
     */
    public static function deniesRestrictedWrite(array $post, ?User $user, $storeId = null): bool
    {
        if (self::permits($user)) {
            return false;
        }

        $restricted = [];
        foreach (array_keys($post) as $name) {
            // An integer key is a name too: the writers' `where name = 0` matches EVERY non-numeric name on MySQL.
            if (! is_string($name) || self::isOpaqueName($name)) {
                return true;
            }

            if (self::isRestrictedName($name)) {
                $restricted[] = $name;
            }
        }

        if ($restricted === []) {
            return false;
        }

        $stored = self::storedRows($restricted, $storeId);

        foreach ($restricted as $name) {
            $normal = self::normaliseName($name);
            $row = $stored[$normal] ?? null;

            if ($row !== null && $row['name'] !== $name) {
                return true;
            }

            $posted = $post[$name];

            if ($posted !== null && ! is_scalar($posted)) {
                return true;
            }

            if (is_bool($posted)) {
                $posted = $posted ? '1' : '0';
            }

            $held = $row !== null ? (string) ($row['value'] ?? '') : (self::ABSENT_MEANS[$normal] ?? '');

            if ((string) ($posted ?? '') !== $held) {
                return true;
            }
        }

        return false;
    }

    /**
     * deniesRestrictedWrite() over a request, and for a user who may not hold the Settings permission the restricted names that are
     * UNCHANGED are removed from the request: the writers key their rows by the writing user too (`updateOrCreate` on name, store AND
     * created_by), so an "unchanged" resubmission would still insert a second row owned by the staff member, which every
     * newest-row reader then prefers over the owner's later edit. A resubmitted value is therefore not written at all.
     *
     * @param  list<string>  $except  exact (lower-case) names to leave alone, for a handler that has its own rule for them
     */
    public static function refusesRestrictedPost(Request $request, ?User $user, $storeId = null, array $except = []): bool
    {
        $post = array_diff_key($request->all(), array_flip($except));

        if (self::deniesRestrictedWrite($post, $user, $storeId)) {
            return true;
        }

        if (! self::permits($user)) {
            foreach (array_keys($post) as $name) {
                if (is_string($name) && self::isRestrictedName($name)) {
                    $request->request->remove($name);
                    $request->query->remove($name);
                    $request->files->remove($name);
                    if ($request->isJson()) {
                        $request->json()->remove($name);
                    }
                }
            }
        }

        return false;
    }

    /**
     * The five domain-routing settings as the store holds them, as the INPUTS ThemeSettings would have to receive to leave them
     * exactly as they are: `enable_domain` carries the token of the stored mode, `domains` the stored domain, `subdomain` the label
     * the handler appends the platform host to (in sub-domain mode) or the stored value (otherwise).
     *
     * @return array{enable_domain: string, domains: string, subdomain: string}
     */
    public static function domainRoutingHeld($storeId): array
    {
        $stored = self::storedRows(self::DOMAIN_ROUTING, $storeId);
        $value = static fn (string $name): string => trim((string) ($stored[$name]['value'] ?? ''));

        $token = $value('enable_domain') === 'on' ? 'enable_domain'
            : ($value('enable_subdomain') === 'on' ? 'enable_subdomain' : 'enable_storelink');

        return [
            'enable_domain' => $token,
            'domains' => $value('domains'),
            'subdomain' => $token === 'enable_subdomain' ? strtolower(explode('.', $value('subdomain'), 2)[0]) : $value('subdomain'),
        ];
    }

    /**
     * Does this ThemeSettings post TRY to change the routing?  Only a value that is PRESENT and differs counts: the Home and Checkout
     * tabs post none of the routing fields, and a plan with no domain feature renders no radio, so absence is not an attempt (the merge
     * in domainRoutingNeutralised() deals with it). The refusal is for the user's benefit -- they are told, rather than shown a success
     * that changed nothing; it is the merge, not this check, that makes the handler leave the routing alone.
     *
     * `subdomain` is NOT compared: the form posts the store's current slug in a read-only input, which drifts from the stored label
     * after a slug rename, and the merge replaces it anyway.
     *
     * @param  array<string, mixed>  $input
     * @param  array{enable_domain: string, domains: string, subdomain: string}  $held
     */
    public static function domainRoutingDiffers(array $input, array $held): bool
    {
        foreach (self::DOMAIN_ROUTING as $key) {
            if (isset($input[$key]) && ! is_scalar($input[$key])) {
                return true;   // an array, an uploaded file
            }
        }

        // The flags are DERIVED from the token; the form never posts them, and posted raw they are written as they stand.
        if (isset($input['enable_subdomain']) || isset($input['enable_storelink'])) {
            return true;
        }

        $token = (string) ($input['enable_domain'] ?? '');

        if ($token !== '' && $token !== '0' && $token !== $held['enable_domain']) {
            return true;
        }

        // A domain that is posted must be the stored one. With the custom-domain token an ABSENT or emptied field is a request to remove it.
        if (isset($input['domains']) || $token === 'enable_domain') {
            return trim((string) ($input['domains'] ?? '')) !== $held['domains'];
        }

        return false;
    }

    /**
     * What ThemeSettings must be fed so that, for a user who may not change routing, it derives exactly the stored state; null when
     * the post tries to change it (refuse). The caller merges the result over the request and drops the five routing names from what
     * it writes.
     *
     * A post with no token (the Home and Checkout tabs) is not turned into a "switch to ..." request: with the stored mode custom the
     * handler's token is `enable_domain` (a no-op for an unchanged domain; ANY other token deletes the stored domain at the hosting
     * panel), otherwise `enable_storelink`, which reaches no hosting-panel call in the other two modes.
     *
     * @param  array<string, mixed>  $input
     * @return array{enable_domain: string, domains: string, subdomain: string}|null
     */
    public static function domainRoutingNeutralised(array $input, $storeId): ?array
    {
        $held = self::domainRoutingHeld($storeId);

        if (self::domainRoutingDiffers($input, $held)) {
            return null;
        }

        $token = (string) ($input['enable_domain'] ?? '');
        if ($token === '' || $token === '0') {
            $held['enable_domain'] = $held['enable_domain'] === 'enable_domain' ? 'enable_domain' : 'enable_storelink';
        }

        return $held;
    }

    /**
     * The newest stored row of each name, keyed by the NORMALISED name, read from the table (not through the model, which is
     * `Cachable` and is not flushed by the admin API's raw `insert ... on duplicate key`).
     *
     * @param  list<string>  $names
     * @return array<string, array{name: string, value: mixed}>
     */
    private static function storedRows(array $names, $storeId): array
    {
        if ($storeId === null || $storeId === '' || $names === []) {
            return [];
        }

        $wanted = array_values(array_unique(array_map([self::class, 'normaliseName'], $names)));

        $out = [];
        foreach (DB::table('settings')->where('store_id', $storeId)->whereIn(DB::raw('LOWER(TRIM(name))'), $wanted)->orderBy('id')->get(['name', 'value']) as $row) {
            $out[self::normaliseName((string) $row->name)] = ['name' => (string) $row->name, 'value' => $row->value];
        }

        return $out;
    }

    /** @return list<string> lower-cased: every restricted name that is not matched by a pattern */
    private static function restrictedNames(): array
    {
        static $names = null;

        if ($names === null) {
            $names = self::RESTRICTED_EXACT;
            foreach ((new \App\Http\Controllers\PaymentSettingController())->getPaymentGateways() as $gateway) {
                $names[] = $gateway['id'] . '_image';
                unset($gateway['id']);
                array_walk_recursive($gateway, function ($value) use (&$names) {
                    if (is_string($value)) {
                        $names[] = $value;
                    }
                });
            }
            $names = array_values(array_unique(array_map('strtolower', $names)));
        }

        return $names;
    }

    /** May this user write through a Store Setting handler?  The Settings permission includes it; otherwise `Manage Store Setting`. */
    public static function permitsStoreScreen(?User $user): bool
    {
        return self::permits($user) || ($user !== null && $user->isAbleTo(self::STORE_PERMISSION));
    }

    public static function permits(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (strtolower(trim((string) $user->type)) === 'super admin') {
            return true;
        }

        if ((int) $user->creatorId() === (int) $user->id) {
            return true;
        }

        return $user->isAbleTo(self::PERMISSION);
    }
}
