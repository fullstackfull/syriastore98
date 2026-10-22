<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P1-INV-13 — the customer importers told the merchant things that were not true.
 * =============================================================================================
 *
 * Three defects, all in what the handler REPORTS rather than in what it writes, which is why no
 * ownership or tenancy test would have found them.
 *
 *   1. `ShopifyCustomerController` flashed "Customer successfully Add , We set customer password
 *      1234." while the line above it sets `Hash::make(Str::random(64))`. A merchant reading that
 *      tells their customer a password that does not work, and it advertises a trivially guessable
 *      one that does not exist. The WooCommerce twin already said only "Customer successfully Add."
 *
 *   2. `WoocomCustomerController` flashed its FAILURE as `success`: `catch (\Exception $e) { ...
 *      with('success', __('This email alredy use.')) }`. The merchant was told the import worked.
 *
 *   3. BOTH labelled every failure "this email is already used" — and MEASURED, that cause is never
 *      the real one. `customers.email` carries NO unique index: no migration declares one, so a
 *      duplicate e-mail does not throw at all, it inserts a SECOND customer row. What actually
 *      reaches those catch arms is a `QueryException` from `save()` (a NOT NULL column, an over-long
 *      value) or a null payload field — reported to the merchant as a duplicate they do not have.
 *
 * WHY THIS IS A SOURCE RATCHET AND NOT AN END-TO-END DRIVE. Reaching those handlers requires a live
 * Shopify or WooCommerce store to answer the outbound call, which this environment has not got and
 * which a test must not depend on. So the assertions are made against the comment-stripped source,
 * plus the one thing that IS measurable here and is the premise for defect 3: the absence of the
 * unique index.
 *
 * `assertNotContains`-style source checks are weak evidence in general. They are the right instrument
 * HERE because the defect IS a string in the source — what the merchant is shown — rather than a
 * behaviour with another observable.
 */
class ImportCustomerFailureReportingTest extends TestCase
{
    use RefreshDatabase;

    private const IMPORTERS = [
        'app/Http/Controllers/ShopifyCustomerController.php',
        'app/Http/Controllers/WoocomCustomerController.php',
    ];

    /** comment-stripped source, so a docblock explaining the defect cannot satisfy or fail a ratchet */
    private function liveSource(string $relative): string
    {
        $stripped = '';

        foreach (token_get_all((string) file_get_contents(base_path($relative))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }

    /**
     * THE PREMISE FOR DEFECT 3, measured rather than assumed. If a unique index on `customers.email`
     * were ever added, "this email is already used" would become a REAL cause and these handlers would
     * need a genuine duplicate branch — so this test failing is a signal to revisit them, not noise.
     */
    public function test_customers_email_has_no_unique_index_so_a_duplicate_cannot_throw(): void
    {
        $this->assertTrue(Schema::hasTable('customers'), 'premise: the table exists');
        $this->assertTrue(Schema::hasColumn('customers', 'email'), 'premise: the column exists');

        $indexes = collect(Schema::getIndexes('customers'))
            ->filter(fn (array $index) => ($index['unique'] ?? false) && in_array('email', $index['columns'] ?? [], true));

        $this->assertCount(
            0,
            $indexes,
            'customers.email now has a unique index, so a duplicate e-mail CAN throw: the import handlers '
                . 'need a real duplicate branch and this test must be rewritten'
        );
    }

    /** Defect 1: the password the merchant is told about must be the one the code sets. */
    public function test_no_importer_claims_it_set_the_password_to_1234(): void
    {
        foreach (self::IMPORTERS as $relative) {
            $source = $this->liveSource($relative);

            $this->assertStringNotContainsString(
                '1234',
                $source,
                basename($relative) . ' still tells the merchant a literal password; the code sets Str::random(64)'
            );
        }
    }

    /** And the handler that sets a random password says so, rather than naming a value. */
    public function test_the_shopify_importer_directs_the_merchant_to_password_reset(): void
    {
        $source = $this->liveSource(self::IMPORTERS[0]);

        $this->assertStringContainsString('Str::random(64)', $source, 'premise: the password really is random');
        $this->assertStringContainsString(
            'password reset',
            $source,
            'the success message should tell the merchant how the customer gets a password'
        );
    }

    /**
     * Defect 2: no catch arm in these importers may flash `success`.
     *
     * Keyed on the CONSTRUCT, not on the message text: `with('success'` inside a catch is the defect
     * whatever words follow it, and a future handler mislabelling a different failure is caught too.
     */
    public function test_no_import_failure_is_flashed_as_success(): void
    {
        foreach (self::IMPORTERS as $relative) {
            $source = $this->liveSource($relative);

            // every catch block body, non-greedily up to its closing brace at the same indentation
            $catches = [];
            preg_match_all('/catch\s*\([^)]*\)\s*\{(.*?)\n\s*\}/s', $source, $catches);

            $this->assertNotEmpty($catches[1], basename($relative) . ' has no catch blocks -- this ratchet is vacuous');

            foreach ($catches[1] as $body) {
                $this->assertStringNotContainsString(
                    "with('success'",
                    $body,
                    basename($relative) . ' flashes a FAILURE as success: the merchant is told the import worked'
                );
            }
        }
    }

    /**
     * Defect 3: a failure of unknown cause must not be labelled as a duplicate e-mail, because a
     * duplicate e-mail cannot be the cause (see the premise test above).
     */
    public function test_no_importer_labels_an_unknown_failure_as_a_duplicate_email(): void
    {
        foreach (self::IMPORTERS as $relative) {
            $source = $this->liveSource($relative);

            foreach (['This email already used', 'This email alredy use'] as $claim) {
                $this->assertStringNotContainsString(
                    $claim,
                    $source,
                    basename($relative) . " still reports every failure as [{$claim}], which cannot be the cause"
                );
            }

            // and the replacement logs, so the real cause is recoverable instead of guessed at
            $this->assertStringContainsString(
                'Log::error',
                $source,
                basename($relative) . ' discards the real exception instead of logging it'
            );
        }
    }
}
