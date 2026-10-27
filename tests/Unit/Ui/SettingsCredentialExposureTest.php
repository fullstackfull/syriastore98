<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * F117 — EVERY STORED CREDENTIAL ON THE SETTINGS SCREENS IS RENDERED INTO THE PAGE IN PLAINTEXT.
 * ==============================================================================================
 *
 * P2-05-7 and P2-05-8 both carry the acceptance condition "no secret or credential is rendered
 * into the page". This measures whether that holds today. It does not: sixteen credential-bearing
 * fields across eleven files echo their stored value, every one of them into `type="text"` or
 * `type="hidden"` — not one is a `type="password"`, so they are not even masked by the browser.
 *
 * THIS TEST PINS THE DEFECT RATHER THAN FIXING IT, and says so plainly. The fix is the body of
 * P2-05-7/P2-05-8 and it is not a one-liner, because masking the display without also changing
 * the save path would be far worse than the exposure: these panels post every field on every
 * save, so rendering an empty box and saving what is posted would ERASE the merchant's SMTP
 * password, Twilio token and S3 secret the first time anyone pressed Save on an unrelated
 * setting. Display and persistence have to change together, per panel.
 *
 * What the test does buy in the meantime is that the list cannot GROW silently: a new settings
 * panel that echoes a credential fails here and names itself.
 *
 * THE SCANNER IS QUOTE-AWARE ON PURPOSE, AND THAT IS NOT A DETAIL. The first version of this
 * measurement used `<input\b[^>]*>` and reported FOURTEEN. A `>` inside a Blade expression —
 * `value="{{ $key_data->key }}"`, `{{auth()->user()->deliverysyria_secret}}` — ends that match
 * early, so the `value` attribute was never seen and those fields read as safe. Two of the
 * sixteen were missed that way, including a per-merchant courier secret. A scanner that stops at
 * the first `>` systematically under-reports exactly the fields whose values come from an object,
 * which is most of them.
 */
class SettingsCredentialExposureTest extends TestCase
{
    private const VIEW_ROOT = 'resources/views/setting';

    /** Field-name substrings that mean "this holds a credential". */
    private const CREDENTIAL_PATTERN =
        '/(secret|api_key|apikey|password|token|private_key|auth_key|access_key|client_secret|'
        . '_sid\b|salt|signature|merchant_key|encryption|credential)/i';

    /**
     * THE MEASURED STATE, as `path:line name`. Sorted, and exhaustive: the assertion compares the
     * whole set, so a field that is FIXED must be removed from this list and one that is ADDED
     * fails until it is justified.
     *
     * `mail_encryption` appears twice from one file because `email_fields.blade.php` renders the
     * same field in two branches. It is listed as the scanner finds it rather than deduplicated,
     * so the count matches the fields a reader will actually find in the file.
     */
    private const KNOWN_EXPOSED = [
        'resources/views/setting/chatgpt_setting.blade.php:36 api_key[]',
        'resources/views/setting/email_fields.blade.php:163 mail_password',
        'resources/views/setting/email_fields.blade.php:168 mail_encryption',
        'resources/views/setting/email_fields.blade.php:173 mail_encryption',
        'resources/views/setting/index.blade.php:251 delivery_syria_token',
        'resources/views/setting/recaptcha_setting.blade.php:49 google_recaptcha_secret',
        'resources/views/setting/shopify_setting.blade.php:33 shopify_access_token',
        'resources/views/setting/storage_setting.blade.php:74 s3_secret',
        'resources/views/setting/storage_setting.blade.php:152 wasabi_secret',
        'resources/views/setting/test_mail.blade.php:8 mail_password',
        'resources/views/setting/test_mail.blade.php:9 mail_encryption',
        'resources/views/setting/test_whatsappmessage.blade.php:6 whatsapp_access_token',
        'resources/views/setting/twilio_setting.blade.php:23 twilio_sid',
        'resources/views/setting/twilio_setting.blade.php:30 twilio_token',
        'resources/views/setting/whatsapp_notify_setting.blade.php:27 whatsapp_access_token',
        'resources/views/setting/woocom_setting.blade.php:45 woocommerce_consumer_secret',
    ];

    /**
     * The repository root, derived from this file's own location rather than from `base_path()`.
     *
     * This is a pure unit test: it reads files and parses text, and booting the framework to ask
     * for a path it already knows would be the only reason it needed an application at all.
     */
    private function repoPath(string $relative = ''): string
    {
        $root = dirname(__DIR__, 3);

        return $relative === '' ? $root : $root . '/' . $relative;
    }

    /**
     * Every `<input>` tag in a blade, with its `>` found the way a browser finds it: by skipping
     * over quoted attribute values. See the class docblock for what the naive version missed.
     *
     * @return list<array{0:int,1:string}> [offset, tag]
     */
    private function inputTags(string $text): array
    {
        $out = [];
        $i = 0;

        while (($i = strpos($text, '<input', $i)) !== false) {
            $j = $i + 6;
            $quote = null;

            while ($j < strlen($text)) {
                $c = $text[$j];

                if ($quote !== null) {
                    if ($c === $quote) {
                        $quote = null;
                    }
                } elseif ($c === '"' || $c === "'") {
                    $quote = $c;
                } elseif ($c === '>') {
                    break;
                }

                $j++;
            }

            $out[] = [$i, substr($text, $i, $j - $i + 1)];
            $i = $j + 1;
        }

        return $out;
    }

    private function attribute(string $tag, string $name): ?string
    {
        return preg_match('/' . $name . '\s*=\s*"([^"]*)"/', $tag, $m) ? $m[1] : null;
    }

    /** @return list<string> "path:line name" for every credential field echoing a stored value */
    private function exposedFields(): array
    {
        $root = $this->repoPath(self::VIEW_ROOT);
        $found = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $path = str_replace($this->repoPath() . '/', '', $file->getPathname());
            $text = (string) file_get_contents($file->getPathname());

            foreach ($this->inputTags($text) as [$offset, $tag]) {
                $name = $this->attribute($tag, 'name');

                if ($name === null || ! preg_match(self::CREDENTIAL_PATTERN, $name)) {
                    continue;
                }

                $value = (string) $this->attribute($tag, 'value');

                if (! str_contains($value, '{{') && ! str_contains($value, '{!!')) {
                    continue;
                }

                $line = substr_count(substr($text, 0, $offset), "\n") + 1;
                $found[] = "$path:$line $name";
            }

            /* `Form::text('name', <value>, ...)` — the helper form, where anything but a literal
               null or empty string is a stored value being echoed. */
            if (preg_match_all(
                "/Form::(?:text|password|number|hidden)\(\s*'([^']+)'\s*,\s*(.+?),\s*\[/s",
                $text,
                $matches,
                PREG_OFFSET_CAPTURE
            )) {
                foreach ($matches[1] as $k => [$name, $_]) {
                    if (! preg_match(self::CREDENTIAL_PATTERN, $name)) {
                        continue;
                    }

                    $value = trim(preg_replace('/\s+/', ' ', $matches[2][$k][0]));

                    if (in_array($value, ['null', "''", '""'], true)) {
                        continue;
                    }

                    $line = substr_count(substr($text, 0, $matches[0][$k][1]), "\n") + 1;
                    $found[] = "$path:$line $name";
                }
            }
        }

        sort($found);

        return $found;
    }

    /** The scanner must actually reach the views, or "nothing exposed" is a false reading. */
    public function test_the_scan_reaches_the_settings_views(): void
    {
        $this->assertDirectoryExists($this->repoPath(self::VIEW_ROOT));

        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->repoPath(self::VIEW_ROOT))) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $count++;
            }
        }

        $this->assertGreaterThanOrEqual(25, $count,
            'premise: the settings directory holds the ~30 panels this slice is about. A smaller '
            . 'number means the scan is looking in the wrong place and every assertion below is '
            . 'vacuous.');
    }

    /**
     * The scanner must find a `value` attribute whose Blade expression contains `->`. This is the
     * bug that made the first measurement report 14 instead of 16, and it is asserted rather than
     * described because a future simplification of `inputTags()` would silently re-introduce it.
     */
    public function test_the_scanner_sees_through_an_arrow_in_a_blade_expression(): void
    {
        $tags = $this->inputTags('<input type="text" name="api_key" value="{{ $row->key }}" />');

        $this->assertCount(1, $tags);
        $this->assertSame('{{ $row->key }}', $this->attribute($tags[0][1], 'value'),
            'the tag scanner stopped at the `>` inside the Blade expression, so it cannot see the '
            . 'value of any field whose source is an object property — which is most of them');
    }

    /**
     * THE RATCHET. This asserts the defect as it stands, so it cannot grow unnoticed.
     */
    public function test_no_settings_screen_newly_echoes_a_stored_credential(): void
    {
        $exposed = $this->exposedFields();
        $known = self::KNOWN_EXPOSED;
        sort($known);

        $new = array_values(array_diff($exposed, $known));
        $fixed = array_values(array_diff($known, $exposed));

        $this->assertSame([], $new,
            "a settings screen has started rendering a stored credential into the page:\n  "
            . implode("\n  ", $new)
            . "\n\nP2-05-7 and P2-05-8 both require that no secret or credential is rendered. "
            . 'Masking the display is NOT enough on its own: these panels post every field on '
            . 'every save, so an empty box plus an unchanged save path erases the stored '
            . 'credential. Change the display and the persistence together.');

        $this->assertSame([], $fixed,
            "a credential exposure recorded in KNOWN_EXPOSED is gone — which is good. Remove it "
            . "from the list so the ratchet tightens:\n  " . implode("\n  ", $fixed));
    }

    /**
     * THE FIX IS NOT A DESIGN QUESTION — IT IS ALREADY IN THIS CODEBASE, ONCE.
     *
     * An earlier version of this test asserted that NOT ONE credential field was masked. That was
     * wrong, and the test itself caught it: `otp_setting.blade.php` renders `api_secret` as
     *
     *     type="password", autocomplete="new-password", NO value attribute,
     *     placeholder="Leave empty to keep current secret"
     *
     * and `OtpIntegrationController:40` closes the loop that makes it safe —
     * `if ($request->filled('api_secret')) { $integration->api_secret = $request->api_secret; }`
     * — so an empty post keeps the stored secret instead of erasing it.
     *
     * BOTH HALVES ARE THE PATTERN. Masking the field alone would be worse than the exposure,
     * because these panels post every field on every save: an empty box plus an unchanged save
     * path wipes the credential the first time anyone presses Save on an unrelated setting. The
     * sixteen fields in KNOWN_EXPOSED should be migrated to exactly this shape, display and
     * persistence together, as the body of P2-05-7 / P2-05-8.
     *
     * This test pins the precedent so it cannot be removed while the sixteen still point at it,
     * and it fails when a SECOND field adopts the pattern — at which point that field comes off
     * KNOWN_EXPOSED and goes on here, and the ratchet has tightened by one.
     */
    public function test_the_masked_credential_pattern_exists_and_is_the_one_to_copy(): void
    {
        $masked = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->repoPath(self::VIEW_ROOT))) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $text = (string) file_get_contents($file->getPathname());

            foreach ($this->inputTags($text) as [$offset, $tag]) {
                $name = $this->attribute($tag, 'name');

                if ($name !== null
                    && preg_match(self::CREDENTIAL_PATTERN, $name)
                    && $this->attribute($tag, 'type') === 'password') {
                    $masked[] = str_replace($this->repoPath() . '/', '', $file->getPathname()) . " $name";
                }
            }
        }

        sort($masked);

        $this->assertSame(
            ['resources/views/setting/otp_setting.blade.php api_secret'],
            $masked,
            'the set of MASKED credential fields has changed. If a field was added, move it off '
            . 'KNOWN_EXPOSED in the same change and confirm its controller guards the write with '
            . '`$request->filled(...)`, or the save will erase the stored secret. If the OTP one '
            . 'was removed, the only working precedent in the codebase has gone with it.'
        );

        /* The display half is worthless without the persistence half, so the precedent is
           asserted as a pair rather than as a rendered attribute. */
        $controller = (string) file_get_contents($this->repoPath('app/Http/Controllers/OtpIntegrationController.php'));

        $this->assertStringContainsString("if (\$request->filled('api_secret'))", $controller,
            'the OTP secret is no longer guarded by `filled()`, so the masked field now ERASES '
            . 'the stored secret on every save that leaves it blank — which is the failure mode '
            . 'the other sixteen fields must not be migrated into.');

        $this->assertStringContainsString('$integration->api_secret = $request->api_secret;', $controller,
            'the guarded write itself is gone, so the masked field stores nothing at all');

        $view = (string) file_get_contents($this->repoPath('resources/views/setting/otp_setting.blade.php'));

        $this->assertStringContainsString('Leave empty to keep current secret', $view,
            'the placeholder that tells the merchant an empty field is not a deletion');
    }
}
