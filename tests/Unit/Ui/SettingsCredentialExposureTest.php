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
    /*
     * F117 IS BEING PAID DOWN PANEL BY PANEL, and this list shrinks as it is. Twilio, Shopify, WhatsApp, reCAPTCHA and WooCommerce
     * left on 2026-10-27 when that panel moved to `<x-ss.secret-field>` plus
     * `SecretSetting::resolve()` on its save path — both halves together, because masking the
     * display over an unconditional `$post[$k] = $request->$k` erases the credential on the next
     * save of anything else on the same panel.
     *
     * The ratchet asserts this list EXACTLY, in both directions: a new exposure fails it, and so
     * does a fixed one left in the list. That second direction is what forced this edit, and it
     * is the half that keeps the list honest.
     */
    /**
     * FIELDS THE PATTERN MATCHES THAT ARE NOT CREDENTIALS, named one at a time with the reason.
     *
     * `CREDENTIAL_PATTERN` includes `encryption` because `encryption_key` really is a secret.
     * `mail_encryption` is not: it is the transport mode, `tls` or `ssl` or empty. Carrying it in
     * `KNOWN_EXPOSED` was dishonest in both directions — it inflated the count of credentials
     * still owed, and it invited the next person to "fix" it by masking a field whose whole
     * purpose is to show which mode is selected. A masked `tls` helps nobody and hides nothing.
     *
     * This list is deliberately per-FIELD rather than a pattern change: narrowing the pattern
     * would also stop it catching a future `payment_encryption_secret`.
     */
    private const NOT_A_CREDENTIAL = [
        // The SMTP transport mode: `tls`, `ssl`, or empty. Not a secret, and useful to see.
        'mail_encryption',
    ];

    private const KNOWN_EXPOSED = [];

    /**
     * The repository root, derived from this file's own location rather than from `base_path()`.
     *
     * This is a pure unit test: it reads files and parses text, and booting the framework to ask
     * for a path it already knows would be the only reason it needed an application at all.
     */
    /**
     * A field name that looks like a credential AND is not on the declared exemption list.
     */
    private static function isCredentialName(string $name): bool
    {
        $bare = rtrim($name, '[]');

        if (in_array($bare, self::NOT_A_CREDENTIAL, true)) {
            return false;
        }

        return (bool) preg_match(self::CREDENTIAL_PATTERN, $name);
    }

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
    /**
     * Blade comments removed, because a source-text scan that reads them matches its OWN
     * DOCUMENTATION. This project has been bitten by that three times in this phase alone — an
     * `@php` written inside a `{{-- --}}`, a component tag inside a JavaScript comment, and a
     * `role="tablist"` quoted in a note — and the docblocks in this very class quote `<input …>`
     * fragments while explaining what they used to look like. None of those fragments carries a
     * `name` today, so nothing is currently mis-reported; stripping them is what keeps the next
     * explanatory comment from becoming a finding or, worse, from satisfying a ratchet.
     */
    private function withoutBladeComments(string $text): string
    {
        /*
         * BLANKED, NOT DELETED, and the difference matters: one caller turns an input's offset
         * into a LINE NUMBER with `substr_count(substr($text, 0, $offset), "\n")`. Deleting the
         * comments shortens the string, so every reported line number would be too low —
         * silently, and worst in exactly the files with the most explanation. Each comment is
         * replaced by the same number of bytes, with its newlines kept, so offsets and line
         * numbers are unchanged.
         */
        return (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            static fn (array $m): string => str_repeat(' ', strlen($m[0]) - substr_count($m[0], "\n"))
                . str_repeat("\n", substr_count($m[0], "\n")),
            $text,
        );
    }

    private function inputTags(string $text): array
    {
        $text = $this->withoutBladeComments($text);

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

                if ($name === null || ! self::isCredentialName($name)) {
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
                    if (! self::isCredentialName($name)) {
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
     * path wipes the credential the first time anyone presses Save on an unrelated setting.
     *
     * The sixteen fields that were in KNOWN_EXPOSED have now been migrated, display and
     * persistence together, and the list is empty. They did NOT copy this precedent's raw markup:
     * they went to `<x-ss.secret-field>` with `SecretSetting::resolve()` on the save path, which
     * is the same contract in one place instead of sixteen. This test's job is therefore no
     * longer "there is one precedent to copy" but "these are the only RAW masked inputs left" —
     * a new one appearing means somebody hand-rolled the pattern instead of using the component,
     * which is how the display half ends up shipped without the persistence half.
     *
     * Two are expected, and neither can be the component:
     *
     *   - `otp_setting.blade.php api_secret` — the original precedent, guarded by
     *     `$request->filled('api_secret')` in OtpIntegrationController. Asserted as a pair below.
     *   - `setting/index.blade.php api_key[]`, ONCE — the add-a-row template for the ChatGPT key
     *     repeater. It is a JavaScript string literal cloned into the DOM on click, so Blade
     *     never renders it and a Blade component cannot be used. It carries no stored value by
     *     construction (a cloned row is always a NEW key), so there is no persistence half to
     *     pair: the F117 risk it carries is only shoulder-surfing a key being typed.
     *
     * IT USED TO BE LISTED TWICE, and that was the finding rather than a quirk of the count: the
     * repeater was implemented TWICE in `index.blade.php`, each copy with its own `fieldHTML`.
     * The first bound with `$('.add_button').click(...)` on document-ready, when the settings
     * form has not been fetched and `.add_button` matches nothing, so it bound to zero elements
     * and never ran. Two templates for one control is how one of them came to keep injecting
     * `class="form-control"` after the panel moved to the design system. The dead copy is gone,
     * so this list has one entry — which is exactly the direction of change this assertion is
     * built to make somebody read.
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
                    && self::isCredentialName($name)
                    && $this->attribute($tag, 'type') === 'password') {
                    $masked[] = str_replace($this->repoPath() . '/', '', $file->getPathname()) . " $name";
                }
            }
        }

        sort($masked);

        $this->assertSame(
            [
                'resources/views/setting/index.blade.php api_key[]',
                'resources/views/setting/otp_setting.blade.php api_secret',
            ],
            $masked,
            'the set of RAW masked credential inputs has changed. A new entry means a credential '
            . 'field was hand-rolled as `type="password"` instead of going through '
            . '`<x-ss.secret-field>`: use the component, which carries the hint and the '
            . '`autocomplete="new-password"` with it, and pair it with '
            . '`SecretSetting::resolve()` on the save path — a masked box over an unguarded save '
            . 'ERASES the stored credential. A removed entry means one of the three documented '
            . 'exceptions is gone; read the docblock before accepting that.'
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

    /**
     * THE EXEMPTION IS EXACT, so it cannot quietly swallow a real credential.
     *
     * `NOT_A_CREDENTIAL` exists because `mail_encryption` is a transport mode, not a secret. The
     * risk of any such list is that it grows a pattern and starts hiding things: this asserts the
     * match is on the whole field name, so a field whose name merely CONTAINS the exempt one —
     * or which shares its keyword — is still caught.
     */
    public function test_the_not_a_credential_exemption_matches_whole_names_only(): void
    {
        $method = new \ReflectionMethod(self::class, 'isCredentialName');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, 'mail_encryption'),
            'the declared exemption must apply');

        foreach ([
            'payment_encryption_secret',
            'mail_encryption_key',
            'encryption_key',
            'mail_password',
            's3_secret',
            'api_key[]',
        ] as $name) {
            $this->assertTrue($method->invoke(null, $name),
                "`$name` must still be treated as a credential — the exemption is for one exact "
                . 'field name, not for a keyword');
        }
    }

    /**
     * THE BLIND SPOT THAT HID THE LARGEST EXPOSURE OF ALL, now closed.
     *
     * Every other assertion in this class works by reading a field's NAME and deciding whether
     * it sounds like a credential. `payment_partials/payment_gateway.blade.php` defeated that
     * completely: it is rendered once per gateway from
     * `SettingController::paymentSettingArray()`, and its input was
     *
     *     <input type="text" name="{{ $field['key'] }}" value="{{ $setting[$field['key']] ?? '' }}">
     *
     * The name is an EXPRESSION, so `isCredentialName()` was asked whether `{{ $field['key'] }}`
     * sounds like a secret and said no — for all 108 fields, 58 of them secrets, across 52
     * providers. The whole payment screen was invisible to a test written to find exactly this,
     * and it stayed invisible through every run that reported the exposure list empty.
     *
     * So the property asserted here is different in kind: an input whose name is a Blade
     * EXPRESSION cannot be classified by name at all, and if it also renders a value it is an
     * unclassifiable disclosure risk. Every such input must be listed below with a reason. The
     * list is asserted exactly, in both directions, like the others.
     *
     * A LOOPED FIELD IS NOT BANNED, only unexamined ones. The payment partial still loops; it
     * now branches on the field's own `'secret'` flag and hands the secret case to
     * `<x-ss.secret-field>`, which renders no value.
     *
     * WHAT THIS TEST IS, STATED EXACTLY, because it is easy to read it as more than it is. It is
     * an INVENTORY, not a guard. It reads SOURCE TEXT, so it sees one `<input>` in the loop
     * whether that loop guards it or not — breaking the guard (`@if (false)`) leaves the source
     * with the same single dynamic input and this test still passes. MEASURED, by doing exactly
     * that. What it buys is that a NEW looped input rendering stored values cannot appear
     * unreviewed, and that the reviewed ones are written down.
     *
     * The guard itself is asserted two other ways, both of which do bite:
     * `test_the_payment_partial_branches_on_the_secret_flag` below, structurally, and
     * `shoot-settings-live.cjs`, which reads the rendered DOM and fails on any credential-named
     * field carrying a value.
     */
    public function test_an_input_whose_name_is_an_expression_renders_no_value(): void
    {
        $dynamic = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->repoPath(self::VIEW_ROOT))) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $text = (string) file_get_contents($file->getPathname());

            foreach ($this->inputTags($text) as [$offset, $tag]) {
                $name = $this->attribute($tag, 'name');
                $value = $this->attribute($tag, 'value');

                // Only inputs whose NAME is a Blade expression -- those are the ones no
                // name-based rule can judge. A literal name is covered by the assertions above.
                if ($name === null || ! str_contains($name, '{{')) {
                    continue;
                }

                /*
                 * Only a value read from STORED settings can disclose a credential. A radio
                 * whose `value` is its own loop variable -- `{{ $mode }}` over the gateway's
                 * `mode_options` -- renders `sandbox` or `live`, which is a constant set
                 * written in the source, not anything a merchant saved. Matching every dynamic
                 * value instead would report that radio and teach the reader to skim the list.
                 */
                if ($value === null || ! str_contains($value, '$setting[')) {
                    continue;
                }

                $dynamic[] = str_replace($this->repoPath() . '/', '', $file->getPathname())
                    . ' ' . trim($name);
            }
        }

        sort($dynamic);

        $this->assertSame(
            [
                /*
                 * The PUBLIC branch of the payment partial's field loop, and it is public by an
                 * explicit per-field decision rather than by a guess at its name:
                 * `paymentSettingArray()` marks each of the 108 fields `'secret' => true|false`,
                 * the secret ones never reach this branch, and
                 * `PaymentGatewayFieldContractTest` fails if any field omits the flag. A
                 * merchant id or a region is meant to be readable, so this one renders its
                 * value on purpose.
                 *
                 * It stays on the list rather than being filtered out, because the list is the
                 * record of which looped inputs somebody has actually looked at. If the branch
                 * ever stops checking the flag, this entry is where the reader is already
                 * looking.
                 */
                "resources/views/setting/payment_partials/payment_gateway.blade.php {{ \$field['key'] }}",
            ],
            $dynamic,
            "these inputs take BOTH their name and their value from a Blade expression, so no "
            . "name-based rule can tell whether they render a credential:\n  "
            . implode("\n  ", $dynamic)
            . "\n\nThis is how the payment screen's 58 gateway secrets stayed invisible to this "
            . 'test. Either render no value (use `<x-ss.secret-field>`), or branch on an explicit '
            . "flag the way `payment_gateway.blade.php` now branches on `\$field['secret']`.",
        );
    }

    /**
     * The payment partial's secret branch exists and is driven by the flag.
     *
     * This is the assertion that bites when the guard is removed. The inventory above does not:
     * source text contains the same looped `<input>` whether the branch checks the flag or not,
     * which was measured by setting the condition to `@if (false)` and watching it still pass.
     *
     * Three things are required and each has failed somewhere in this codebase before:
     * the branch must read `$field['secret']`; the secret case must go through
     * `<x-ss.secret-field>` rather than a hand-rolled `type="password"` (which ships without the
     * hint and without the `autocomplete="new-password"`); and the default for a missing flag
     * must be SECRET, so a field added without one is masked rather than exposed.
     */
    public function test_the_payment_partial_branches_on_the_secret_flag(): void
    {
        $path = $this->repoPath(self::VIEW_ROOT) . '/payment_partials/payment_gateway.blade.php';
        $text = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            "/@if\s*\(\s*\\\$field\['secret'\]\s*\?\?\s*true\s*\)/",
            $text,
            'the payment partial no longer branches on each field\'s `secret` flag with a '
            . 'default of TRUE. Without the branch every gateway credential is rendered into the '
            . 'page again (F117\'s largest exposure, 58 secrets across 52 providers); without '
            . 'the `?? true` default a field added with no flag is exposed rather than masked.',
        );

        $this->assertStringContainsString('<x-ss.secret-field', $text,
            'the secret branch must render through the component, which carries the '
            . '"blank keeps it" hint and the `autocomplete="new-password"` with it');
    }
}
