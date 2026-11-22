<?php

namespace Tests\Unit\Localisation;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * P4-1 -- EVERY MESSAGE A STOREFRONT CONTROLLER EMITS IS DECLARED IN BOTH LANGUAGE FILES.
 * =============================================================================================
 *
 * `__()` falls back to its KEY when nothing is declared, so an untranslated string is invisible
 * to every test that only checks "a message came back": the English storefront is right by
 * accident and the Arabic one answers in English. That is not a hypothetical -- a census of the
 * 25 controllers behind `routes/customer.php` found **115** keys declared in neither file, among
 * them the whole refund-request vocabulary, every OTP sign-in message, and what a shopper reads
 * when a payment fails. 61 were real messages and are now translated; the rest are the exception
 * class below.
 *
 * THE EXCEPTION IS BRAND NAMES, AND IT IS A LIST RATHER THAN A COUNT. "Stripe" is "Stripe" in
 * Arabic, so `__('Stripe')` returning its key is CORRECT, not a gap -- translating it would be
 * the defect. A count would have hidden which 46 they were and would have absorbed a real
 * message the next time one was added. Each is named, so adding a 47th is a deliberate act.
 *
 * SCOPE IS THE STOREFRONT'S OWN CONTROLLERS, taken from `routes/customer.php`'s imports rather
 * than from a hand-written list, so a controller added to those routes is covered the day it is
 * added. Vendored `packages/workdo/` controllers are out of scope: this project does not edit
 * them, and their strings are not ours to declare.
 *
 * COMMENTS ARE STRIPPED. CLAUDE.md records four occasions in one day on which a census was
 * satisfied -- or broken -- by prose about the thing it measures, and this file would be a fifth:
 * the docblock above contains `__('Stripe')`.
 */
class StorefrontMessagesAreDeclaredTest extends TestCase
{
    use StripsSourceComments;

    /**
     * Payment-gateway brands and two literal configuration field names.
     *
     * Every one is a proper noun or an identifier that must read identically in both languages.
     */
    private const UNTRANSLATED_BY_DESIGN = [
        'Aamarpay', 'Benefit', 'Braintree', 'Cashfree', 'Coingate', 'Cybersource', 'DPO Pay',
        'Flutterwave', 'IyziPay', 'Mercado Pago', 'Midtrans', 'Mollie', 'MyFatoorah',
        'Nepalste', 'Ozow', 'PAYMERA_TERMINAL_ID', 'PAYMERA_USERNAME', 'Paddle', 'Paiement Pro',
        'PayFast', 'PayHere', 'PayU', 'PaymentWall', 'Paymera', 'Paystack', 'PhonePe',
        'PowerTranz', 'Razorpay', 'Sofort', 'Sspay', 'Stripe', 'Tap', 'Telegram', 'Xendit',
        'Yookassa', 'myfatoorah.',
    ];

    /** @return array<int, string> the controllers `routes/customer.php` imports, as repo-relative paths */
    private function storefrontControllers(): array
    {
        $routes = $this->sourceWithoutComments('routes/customer.php');

        preg_match_all('/use (App\\\\Http\\\\Controllers\\\\[A-Za-z\\\\]+);/', $routes, $matches);

        $paths = [];

        foreach (array_unique($matches[1]) as $class) {
            $relative = 'app/' . str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php';

            if (is_file(base_path($relative))) {
                $paths[] = $relative;
            }
        }

        sort($paths);

        return $paths;
    }

    /** @return array<int, string> every single-quoted `__()` key in one file */
    private function keysIn(string $relativePath): array
    {
        preg_match_all(
            "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
            $this->sourceWithoutComments($relativePath),
            $matches
        );

        return array_values(array_unique(array_map('stripcslashes', $matches[1])));
    }

    public function test_every_storefront_message_is_declared_in_both_language_files(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);
        $en = json_decode((string) file_get_contents(base_path('resources/lang/en.json')), true);

        $this->assertIsArray($ar);
        $this->assertIsArray($en);

        $undeclared = [];

        foreach ($this->storefrontControllers() as $relative) {
            foreach ($this->keysIn($relative) as $key) {
                if (in_array($key, self::UNTRANSLATED_BY_DESIGN, true)) {
                    continue;
                }

                $missing = [];

                if (! array_key_exists($key, $ar)) {
                    $missing[] = 'ar';
                }

                if (! array_key_exists($key, $en)) {
                    $missing[] = 'en';
                }

                if ($missing !== []) {
                    $undeclared[] = basename($relative) . '  [' . implode(', ', $missing) . ']  "' . $key . '"';
                }
            }
        }

        $this->assertSame([], $undeclared, sprintf(
            "%d storefront message(s) are declared in neither language file, so __() falls back to "
            . "the English key and an Arabic storefront answers an Arabic shopper in English. If a "
            . "key is a brand name, add it to UNTRANSLATED_BY_DESIGN by name:\n%s",
            count($undeclared),
            implode("\n", $undeclared)
        ));
    }

    /**
     * THE PREMISE. `assertSame([], ...)` is satisfied by a scan that found nothing to look at --
     * a changed import syntax in `routes/customer.php`, a regex that stopped matching `__(`, or a
     * `base_path()` that resolves somewhere else would all read as a clean result.
     */
    public function test_the_scan_actually_reads_the_storefront_controllers(): void
    {
        $controllers = $this->storefrontControllers();

        $this->assertGreaterThan(20, count($controllers),
            'Far fewer storefront controllers were found than routes/customer.php imports.');
        $this->assertContains('app/Http/Controllers/CartController.php', $controllers);
        $this->assertContains('app/Http/Controllers/PaymentController.php', $controllers);

        $keys = $this->keysIn('app/Http/Controllers/CartController.php');

        $this->assertGreaterThan(10, count($keys), 'No messages were seen in CartController at all.');
        $this->assertContains('Product not found.', $keys);
    }

    /** Nothing may sit in the brand allowlist that no storefront controller still asks for. */
    public function test_the_brand_allowlist_carries_no_entry_the_code_has_dropped(): void
    {
        $seen = [];

        foreach ($this->storefrontControllers() as $relative) {
            foreach ($this->keysIn($relative) as $key) {
                $seen[$key] = true;
            }
        }

        $stale = array_values(array_filter(
            self::UNTRANSLATED_BY_DESIGN,
            static fn (string $brand): bool => ! isset($seen[$brand])
        ));

        $this->assertSame([], $stale, sprintf(
            "UNTRANSLATED_BY_DESIGN names %d key(s) no storefront controller emits any more: %s. "
            . 'Remove them, so the list keeps meaning "these are deliberately English".',
            count($stale),
            implode(', ', $stale)
        ));
    }
}
