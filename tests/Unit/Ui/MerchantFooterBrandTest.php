<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-A-08 — the merchant footer names the product, not the framework.
 * ================================================================================================
 *
 * MEASURED on /product in all four combinations: the admin footer renders
 * `partision/footerlink.blade.php`, whose text is `date('Y')` followed by the store's own
 * `footer_text` setting, or `config('app.name')` when that setting is unset. On this container it
 * read "2026 Laravel", and the contrast was fine in both themes -- 6.14:1 light, 6.72:1 dark,
 * measured on the `span.text-muted` that renders the words rather than on the `<footer>` whose
 * inherited colour nothing paints with. (Reading the wrapper reported 1.20:1 and looked like a
 * defect; it was the third false reading of that shape in this phase.)
 *
 * THE DEFECT IS IN `.env.example`, AND IT IS COMMITTED. It shipped `APP_NAME=Laravel`, and
 * `.env.example` is what a fresh install copies -- so every new deployment put the FRAMEWORK's
 * name in its merchant footer. `.env.testing` already carried "Store Syria (testing)", which is
 * how the discrepancy showed: the project knew the right value and the example did not.
 *
 * THE BLADE FALLBACK WAS HYGIENE, NOT A REPAIR, and saying otherwise would be a false claim.
 * `config('app.name', 'E-CommerceGo')` applies its default only when the KEY IS ABSENT, and
 * `config/app.php` always defines it: setting `app.name` to null and reading it back returns
 * null, not the default. Measured in a booted application. So the vendor's product name could
 * never actually reach a merchant's screen. It is changed anyway, because a vendor's brand
 * sitting in this repository's branding path is wrong even when unreachable, and D-40 settled
 * that there is one brand across web and Flutter.
 */
class MerchantFooterBrandTest extends TestCase
{
    private const EXAMPLE = '.env.example';
    private const FOOTER = 'resources/views/partision/footerlink.blade.php';

    public function test_the_example_environment_names_the_product(): void
    {
        $env = $this->read(self::EXAMPLE);

        $this->assertMatchesRegularExpression(
            '/^APP_NAME="Store Syria"$/m',
            $env,
            '`.env.example` is what a fresh install copies, so `APP_NAME=Laravel` there puts the '
            . "framework's name in every new deployment's merchant footer."
        );

        $this->assertSame(
            0,
            preg_match('/^APP_NAME=Laravel\s*$/m', $env),
            'The framework default must not come back.'
        );

        // Anti-vacuity: this is the real example file, not an empty string.
        $this->assertStringContainsString('APP_KEY=', $env);
        $this->assertStringContainsString('DB_CONNECTION=', $env);
    }

    public function test_the_testing_environment_already_agreed_which_is_how_this_was_found(): void
    {
        $this->assertMatchesRegularExpression(
            '/^APP_NAME="Store Syria \(testing\)"$/m',
            $this->read('.env.testing'),
            'The testing environment carries the product name; the example carrying the '
            . "framework's is the discrepancy this slice closed."
        );
    }

    public function test_the_footer_fallback_is_not_the_vendors_product(): void
    {
        $blade = $this->withoutBladeComments($this->read(self::FOOTER));

        $this->assertStringNotContainsString(
            'E-CommerceGo',
            $blade,
            "A vendor's product name must not sit in this repository's branding path, even on a "
            . 'branch that cannot be reached.'
        );

        $this->assertStringContainsString("config('app.name', 'Store Syria')", $blade);

        // The store's own setting still wins over both, which is the behaviour that must not change.
        $this->assertStringContainsString("isset(\$setting['footer_text']) ? \$setting['footer_text']", $blade);
    }

    private function withoutBladeComments(string $blade): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
