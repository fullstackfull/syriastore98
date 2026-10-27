<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * P2-05-8 — the settings panels' POST targets and field names, pinned before they are migrated.
 * =============================================================================================
 *
 * This row's acceptance asks for a test that each panel's POST target and field names are
 * UNCHANGED. It is written BEFORE the design-system migration of those panels rather than after,
 * which is the whole point of it: a migration that renames `twilio_sid` to `twilioSid`, or moves
 * a form from `twilio.settings` to `twilio.setting`, breaks the save silently. The screen still
 * renders, the button still submits, and the value lands nowhere — the handler reads a key that
 * is no longer posted and writes the empty string over a live credential.
 *
 * That is not hypothetical here. F117 converted eleven panels' credential fields in this same
 * phase, and the one name that crossed a boundary (`google_recaptcha_secret` posting into
 * `NOCAPTCHA_SECRET`) was found only because a test drove the real save path.
 *
 * WHAT IT PINS, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It pins the SET of field names each panel posts and the route it posts to. It does NOT pin
 * markup, classes, order, labels or layout — all of which the migration is meant to change. A
 * test that pinned those would have to be rewritten by the same change it exists to guard, and
 * would therefore guard nothing.
 *
 * IT ONLY SEES LITERAL NAMES, AND THAT IS A CONSTRAINT ON THE VIEWS, NOT A GAP IN THE TEST.
 * A panel that renders its fields from a `@foreach` with `:name="$ssName"` is invisible to this
 * scanner — it reported exactly that the first time the refund panel's three toggles were
 * written as a loop. The loop was unwound rather than the scanner taught to follow it: a
 * contract that a refactor can quietly step outside of is weaker than one that requires the
 * names to stay greppable in the file. Dynamic names that are genuinely data — the WhatsApp
 * notification toggles post under a numeric id — are outside the contract by the same rule and
 * are not pinned.
 *
 * A NEW FIELD IS A FAILURE TOO, and on purpose. These handlers write every posted name into
 * `settings` for the caller's store; adding one is a schema decision, not a layout one, and it
 * should be a visible edit to this file rather than a side effect of moving a div.
 */
class SettingsPanelContractTest extends TestCase
{
    private const VIEW_DIR = 'resources/views/setting';

    /**
     * panel => [route, [field names]].
     *
     * Generated from the panels as they stood when F117 closed, then read back and corrected by
     * hand: the generator cannot tell a posted field from a `name` attribute on a button, and
     * two panels carry both.
     */
    private const CONTRACT = [
        'twilio_setting' => ['twilio.settings', [
            'twilio_setting_enabled', 'twilio_sid', 'twilio_token', 'twilio_from',
            'twilio_notification_number',
        ]],
        'shopify_setting' => ['shopify.settings', [
            'shopify_setting_enabled', 'shopify_store_url', 'shopify_access_token',
        ]],
        'woocom_setting' => ['woocommerce.settings', [
            'woocommerce_setting_enabled', 'woocommerce_store_url', 'woocommerce_consumer_key',
            'woocommerce_consumer_secret',
        ]],
        'chatgpt_setting' => ['chatgpt.setting', ['chat_gpt_model', 'api_key']],
        'whatsapp_notify_setting' => ['whatsapp-notification', [
            'whatsapp_access_token', 'whatsapp_phone_number_id',
        ]],
        'storage_setting' => ['storage.settings', [
            'storage_setting',
            'local_storage_max_upload_size', 'local_storage_validation',
            's3_bucket', 's3_endpoint', 's3_key', 's3_max_upload_size', 's3_region',
            's3_secret', 's3_storage_validation', 's3_url',
            'wasabi_bucket', 'wasabi_key', 'wasabi_max_upload_size', 'wasabi_region',
            'wasabi_root', 'wasabi_secret', 'wasabi_storage_validation', 'wasabi_url',
        ]],
        'cookie_setting' => ['cookie.setting', [
            'contactus_url', 'cookie_description', 'cookie_logging', 'cookie_title',
            'enable_cookie', 'more_information_description', 'necessary_cookies',
            'strictly_cookie_description', 'strictly_cookie_title',
        ]],
        'stock_setting' => ['stock.settings', [
            'low_stock_threshold', 'notification', 'notifications', 'out_of_stock_threshold',
            'stock_management',
        ]],
        'refund_setting' => ['refund.settings', ['attachment', 'manage_stock', 'shiping_deduct']],
        'tax_opt_setting' => ['tax-option.settings', [
            'checkout_price', 'display_tax_option', 'price_suffix', 'price_type', 'shop_price',
            'tax_id',
        ]],
        'loyality_pro_setting' => ['loyality.program.settings', [
            'loyality_program_enabled', 'reward_point',
        ]],
    ];

    /**
     * Panels whose form is declared OUTSIDE the panel file — `recaptcha_setting`,
     * `email_fields` and `pixel_field_setting` are fragments included inside a form that lives
     * in `index.blade.php` or is built by the controller. Their FIELDS are contract all the
     * same, so they are pinned here with no route.
     *
     * Named rather than silently skipped: a fragment whose fields are not pinned is exactly the
     * one a migration renames without anyone noticing.
     */
    private const FRAGMENTS = [
        'recaptcha_setting' => [
            'google_recaptcha_key', 'google_recaptcha_secret', 'google_recaptcha_version',
            'recaptcha_module',
        ],
        'email_fields' => [
            'mail_driver', 'mail_encryption', 'mail_from_address', 'mail_from_name',
            'mail_host', 'mail_password', 'mail_port', 'mail_username',
        ],
    ];

    private function source(string $panel): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::VIEW_DIR . '/' . $panel . '.blade.php';

        $this->assertFileExists($path, "the {$panel} panel is gone");

        /* Blade comments are stripped before the scan. These panels now carry comments that
           QUOTE field names while explaining the F117 change, and a raw scan matches its own
           documentation — the same trap that made the report filter-bar census report a defect
           it was describing. */
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
    }

    /** Every `name="..."` and `Form::x('name', ...)` in the panel, as a set. */
    private function fields(string $source): array
    {
        $names = [];

        /* `(?<![:\w-])` so a BOUND attribute is not also matched here: `:name="'api_key[e' . ..."`
           would otherwise be captured raw, leaving a stray quote in the field name. */
        preg_match_all('/(?<![:\w-])name\s*=\s*"([^"]+)"/', $source, $m);
        foreach ($m[1] ?? [] as $n) {
            $names[] = $n;
        }

        /* `Form::text('twilio_sid', ...)`, `Form::select('api_key[]', ...)` and the component
           attribute `name="api_key[e...]"` all name a posted field. */
        preg_match_all('/Form::[a-zA-Z]+\(\s*\'([^\']+)\'/', $source, $m);
        foreach ($m[1] ?? [] as $n) {
            $names[] = $n;
        }

        /* A BOUND name is a Blade EXPRESSION, not a literal: the ChatGPT repeater posts
           `:name="'api_key[e' . $key_data->id . ']'"`, where the field is `api_key` and the rest
           is the row id. Only the leading literal is contract. The first version of this
           extractor took the whole expression and reported two phantom fields. */
        preg_match_all('/:name\s*=\s*"\s*\'([^\']+)\'/', $source, $m);
        foreach ($m[1] ?? [] as $n) {
            $names[] = $n;
        }

        $names = array_map(static function (string $n): string {
            /* `api_key[]`, `api_key[e12]`, the open `api_key[e` of a concatenation, and plain
               `api_key` are ONE field as far as the handler is concerned: the repeater's index
               is data, not contract. */
            return preg_replace('/\[.*$/', '', trim($n));
        }, $names);

        $names = array_values(array_unique(array_filter($names, static fn ($n) =>
            $n !== '' && $n !== '_token' && $n !== '_method')));

        sort($names);

        return $names;
    }

    /** @dataProvider panels */
    public function test_the_panel_still_posts_to_the_same_route(string $panel, string $route): void
    {
        $this->assertStringContainsString("'route' => '{$route}'", $this->source($panel),
            "{$panel} no longer posts to `{$route}`. A panel that renders perfectly and posts "
            . 'somewhere else saves nothing, and nothing on the screen says so.');
    }

    /** @dataProvider panels */
    public function test_the_panel_still_posts_the_same_fields(
        string $panel, string $route, array $expected
    ): void {
        $actual = $this->fields($this->source($panel));

        sort($expected);

        $missing = array_values(array_diff($expected, $actual));
        $added = array_values(array_diff($actual, $expected));

        $this->assertSame([], $missing,
            "{$panel} no longer posts: " . implode(', ', $missing) . ". The handler still reads "
            . 'those names, so it will write the empty string over whatever is stored.');

        $this->assertSame([], $added,
            "{$panel} posts new field(s): " . implode(', ', $added) . '. These handlers write '
            . 'every posted name into `settings` for the caller\'s store, so a new one is a '
            . 'schema decision. Add it to this file in the same change.');
    }

    /** @return array<string, array{0:string,1:string,2:array}> */
    public static function panels(): array
    {
        $out = [];
        foreach (self::CONTRACT as $panel => [$route, $fields]) {
            $out[$panel] = [$panel, $route, $fields];
        }

        return $out;
    }

    /** @dataProvider fragments */
    public function test_the_fragment_still_posts_the_same_fields(string $panel, array $expected): void
    {
        $actual = $this->fields($this->source($panel));

        sort($expected);

        $this->assertSame([], array_values(array_diff($expected, $actual)),
            "{$panel} no longer posts: " . implode(', ', array_diff($expected, $actual)));
        $this->assertSame([], array_values(array_diff($actual, $expected)),
            "{$panel} posts new field(s): " . implode(', ', array_diff($actual, $expected)));
    }

    /** @return array<string, array{0:string,1:array}> */
    public static function fragments(): array
    {
        $out = [];
        foreach (self::FRAGMENTS as $panel => $fields) {
            $out[$panel] = [$panel, $fields];
        }

        return $out;
    }

    /**
     * THE PREMISE: the scanner finds fields at all. Without it every case above passes against
     * an empty file, a renamed directory or a regex that stopped matching.
     */
    public function test_the_scanner_reaches_the_panels(): void
    {
        $total = 0;
        foreach (array_keys(self::CONTRACT) as $panel) {
            $total += count($this->fields($this->source($panel)));
        }

        foreach (array_keys(self::FRAGMENTS) as $panel) {
            $total += count($this->fields($this->source($panel)));
        }

        $this->assertGreaterThanOrEqual(60, $total,
            'the field scanner found almost nothing, so the contract cases are vacuous');
    }
}
