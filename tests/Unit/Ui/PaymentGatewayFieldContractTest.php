<?php

namespace Tests\Unit\Ui;

use App\Http\Controllers\PaymentSettingController;
use App\Http\Controllers\SettingController;
use Tests\TestCase;

/**
 * The payment screen's 108 fields, and the one flag that decides whether each is masked.
 * =================================================================================================
 *
 * `payment_partials/payment_gateway.blade.php` is rendered once per gateway and builds its inputs
 * from `SettingController::paymentSettingArray()`. That made it F117's largest exposure — 52
 * providers' credentials rendered into one page as `type="text" value="…"` — and it makes it the
 * one place where the fix cannot be read off the view: the view just loops.
 *
 * So the decision moved into the data as `'secret' => true|false`, and this test asserts the
 * three properties that make the data trustworthy.
 *
 * 1. EVERY FIELD CARRIES THE FLAG. Not "the secrets are flagged" — every field, including the
 *    public ones, so adding a gateway forces the decision instead of defaulting into one. The
 *    view and the save path both default a missing flag to SECRET, so an omission is a masked
 *    public field rather than an exposed credential; this test is what turns that safe default
 *    into a visible failure.
 *
 * 2. THE OBVIOUS SECRETS ARE FLAGGED SECRET. A spot check with the opposite polarity to the
 *    generator: a field whose name contains `secret`, `private`, `password`, `salt`,
 *    `passphrase` or `auth_code` and does NOT say `public` must be `true`. If somebody later
 *    flips one of these to `false`, this fails and names it. It deliberately does not assert the
 *    whole 58/50 split — that would just restate the generator and would fail for a legitimate
 *    reclassification of an ambiguous name, which the docblock there invites.
 *
 * 3. THE TWO FIELD LISTS AGREE. `PaymentSettingController::getPaymentGateways()` keeps its OWN
 *    list of each gateway's field names, flat strings, with different ids for the same gateway
 *    (`cod` there, `COD` here). The save path resolves secrets by FIELD NAME against the array
 *    above precisely because the ids do not match. A field the save path writes that the display
 *    array does not know would therefore be treated as public and overwritten with the empty
 *    string on an empty post — the F117 data-loss shape. Asserting the two sets agree is what
 *    keeps that from being silent.
 */
class PaymentGatewayFieldContractTest extends TestCase
{
    /** The gateway ids that really render a mode radio, i.e. that declare `mode_options`. */
    private function gatewaysWithModeRadio(): array
    {
        $method = new \ReflectionMethod(SettingController::class, 'paymentSettingArray');
        $method->setAccessible(true);

        $out = [];
        foreach ($method->invoke(app(SettingController::class)) as $gateway) {
            if (! empty($gateway['mode_options'])) {
                $out[strtolower($gateway['id'])] = true;
            }
        }

        return $out;
    }

    /** The description keys, which are rendered as a textarea rather than listed under `fields`. */
    private function descriptionKeys(): array
    {
        $method = new \ReflectionMethod(SettingController::class, 'paymentSettingArray');
        $method->setAccessible(true);

        $out = [];
        foreach ($method->invoke(app(SettingController::class)) as $gateway) {
            if (isset($gateway['description_key'])) {
                $out[$gateway['description_key']] = true;
            }
        }

        return $out;
    }

    /** @return array<int, array{0: string, 1: string, 2: mixed}> [gatewayId, key, secretFlag] */
    private function fields(): array
    {
        $method = new \ReflectionMethod(SettingController::class, 'paymentSettingArray');
        $method->setAccessible(true);

        $out = [];
        foreach ($method->invoke(app(SettingController::class)) as $gateway) {
            foreach ($gateway['fields'] ?? [] as $field) {
                $out[] = [$gateway['id'], $field['key'], $field['secret'] ?? null];
            }
        }

        return $out;
    }

    public function test_the_scan_reaches_the_gateways(): void
    {
        $fields = $this->fields();

        $this->assertGreaterThan(
            100,
            count($fields),
            'premise: paymentSettingArray() yielded ' . count($fields) . ' fields. It had 108 when '
            . 'this was written, so a count this low means the reflection no longer reads it and '
            . 'every assertion below is vacuous.',
        );
    }

    public function test_every_payment_field_declares_whether_it_is_a_secret(): void
    {
        $missing = [];
        foreach ($this->fields() as [$gateway, $key, $secret]) {
            if (! is_bool($secret)) {
                $missing[] = "$gateway.$key";
            }
        }

        $this->assertSame([], $missing,
            "these payment fields do not declare `'secret' => true|false`:\n  "
            . implode("\n  ", $missing)
            . "\n\nEvery field must, so that adding a gateway is a decision rather than a default. "
            . 'The view and the save path both treat a missing flag as SECRET, so nothing is '
            . 'exposed meanwhile — but the field is masked, which for a public id is wrong.');
    }

    public function test_a_field_whose_name_says_secret_is_flagged_secret(): void
    {
        $wrong = [];
        foreach ($this->fields() as [$gateway, $key, $secret]) {
            $saysSecret = (bool) preg_match('/(secret|private|password|passphrase|salt|auth_code)/i', $key);
            $saysPublic = (bool) preg_match('/(public|publishable)/i', $key);

            if ($saysSecret && ! $saysPublic && $secret !== true) {
                $wrong[] = "$gateway.$key is marked " . var_export($secret, true);
            }
        }

        $this->assertSame([], $wrong,
            "these fields name themselves a secret but are not flagged as one:\n  "
            . implode("\n  ", $wrong));
    }

    public function test_the_save_path_and_the_display_array_know_the_same_fields(): void
    {
        $display = [];
        foreach ($this->fields() as [, $key]) {
            $display[$key] = true;
        }

        /* The save list also carries each gateway's enable key, its `_mode` radio and its
           description. The enable key is excluded by shape; the DESCRIPTION is excluded by
           reading the display array's own `description_key`, not by a name pattern — COD's is
           `cod_info` and Bank Transfer's is literally `bank_transfer`, neither of which matches
           the `_unfo` convention the other 50 use. A name-pattern exclusion reported those two
           as unknown fields, which is a false positive this test should not produce.

           `_mode` IS EXCLUDED ONLY WHERE A RADIO IS REALLY RENDERED, i.e. where the display
           array declares `mode_options` for that gateway. A blanket `_mode` exemption was the
           first version and it hid a real case: `paiementpro_mode` is in the save list while
           `paiementpro` declares no `mode_options`, so no radio renders, the field never posts,
           and the save writes '' over the stored mode every time that gateway is enabled. A
           test whose exemption is wider than the thing it describes reports a clean pass over
           exactly the defect it was written to find. */
        $descriptions = $this->descriptionKeys();
        $withModeRadio = $this->gatewaysWithModeRadio();

        $saveOnly = [];
        foreach (app(PaymentSettingController::class)->getPaymentGateways() as $gateway) {
            foreach ($gateway['fields'] ?? [] as $name) {
                $isRenderedModeField = str_ends_with($name, '_mode')
                    && isset($withModeRadio[strtolower($gateway['id'])]);

                if (str_starts_with($name, 'is_')
                    || $isRenderedModeField
                    || isset($descriptions[$name])) {
                    continue;
                }

                if (! isset($display[$name])) {
                    $saveOnly[] = $gateway['id'] . '.' . $name;
                }
            }
        }

        $this->assertSame(
            [
                /*
                 * STILL TRUE AS A MEASUREMENT, NO LONGER DESTRUCTIVE (F143). PaiementPro's save
                 * list carries `paiementpro_mode` while the display array gives that gateway no
                 * `mode_options`, so no mode radio is rendered and the field never posts. It
                 * used to be written as '' on every save that enabled the gateway, because
                 * `processGateway()` could not tell "the merchant cleared this" from "this
                 * control was never on the page".
                 *
                 * The owner's F143 decision was to stop the erasure WITHOUT inventing mode
                 * options or touching collection logic, since what PaiementPro's modes are is a
                 * question about that provider's contract. So the write now skips a field that
                 * is absent from the request entirely, and the stored value survives — pinned by
                 * `PaymentGatewaySecretPersistenceTest::test_a_field_absent_from_the_request_keeps_its_stored_value`.
                 *
                 * This entry stays because the two lists still disagree, and that disagreement
                 * is the thing worth seeing: a save-side field with no display-side control is
                 * still a loose end, just a harmless one now. The mirror case was always
                 * harmless: `cinetpay` and `easebuzz` declare `mode_options` the controller
                 * never saves, so those radios are inert. Rendering a control the save ignores
                 * costs nothing; saving a control that is never rendered cost the stored value.
                 */
                'paiementpro.paiementpro_mode',
            ],
            $saveOnly,
            "PaymentSettingController writes these fields, but paymentSettingArray() does not "
            . "render them, so the save path cannot know whether they are secrets and treats "
            . "them as public — an empty post overwrites the stored value with '':\n  "
            . implode("\n  ", $saveOnly));
    }
}
