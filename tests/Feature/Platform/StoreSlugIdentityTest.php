<?php

namespace Tests\Feature\Platform;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `stores.slug` is the only thing that carries store identity, and it is not fit to.
 * =============================================================================================
 *
 * WHAT THIS FILE IS. A CHARACTERISATION suite for the domain-identity audit
 * (`docs/development/phase-1/P01-DOMAIN-IDENTITY-AUDIT.md` sections 2 and 3). Every assertion below
 * records what the shipped code does TODAY, including where that is a defect. Three of these tests
 * assert broken behaviour on purpose, and each says so in its own name and docblock.
 *
 * WHY ASSERT A DEFECT. The audit's case for a permanent numeric identifier rests on measurements, and
 * a measurement that lives only in a markdown table is not checkable and goes stale silently. Pinning
 * them here means (1) the audit cannot be wrong about the code without CI saying so, and (2) when the
 * audit's stage 4 fixes land, these tests FAIL and must be rewritten to the corrected expectation --
 * which is the intended signal, not an accident. Each such test names the section it backs.
 *
 * WHAT IS NOT HERE. No routing change, no schema change, no numeric identifier. The owner directive of
 * 2026-10-22 requires the URL format and migration strategy to be documented before domain routing is
 * touched, so this file measures the present and changes nothing.
 */
class StoreSlugIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The generator's exact body, lifted from App\Models\User::slugs() minus its database lookup.
     *
     * Why a copy rather than the real method: `User::slugs()` ends with `getRelatedSlugs()`, a query
     * against `stores`, so calling it measures the de-duplication as well as the sanitisation. These
     * first tests are about the SANITISATION alone -- what string the name reduces to before any
     * collision handling. The copy is asserted to be faithful by
     * `test_the_local_copy_of_the_generator_matches_the_shipped_sanitisation`, which drives the real
     * method against an empty table, where de-duplication is a no-op.
     */
    private function sanitise(string $data): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $data);
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);

        if (empty($slug)) {
            $slug = 'store-FIXED-FOR-TEST';     // uniqid() in the original; the branch, not the value, is what matters
        }

        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);
        $slug = preg_replace('/\s+/', '-', trim($slug));

        return strtolower($slug);
    }

    private function merchant(): User
    {
        return User::create([
            'name' => 'Merchant', 'email' => 'm-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'), 'type' => 'admin', 'lang' => 'en',
        ]);
    }

    /** premise: the copy above is the shipped sanitisation, not an approximation of it */
    public function test_the_local_copy_of_the_generator_matches_the_shipped_sanitisation(): void
    {
        $this->assertSame(0, Store::count(), 'premise: an empty stores table, so de-duplication is a no-op');

        foreach (['Damascus Bazaar', 'Shop One', 'a-b', 'Store 42'] as $name) {
            $this->assertSame(
                $this->sanitise($name),
                User::slugs($name),
                "the local copy diverges from User::slugs() for [{$name}], so every measurement below is unsound"
            );
        }
    }

    /**
     * AUDIT SECTION 3.1 -- an Arabic store name collapses to a single hyphen. THIS IS A DEFECT.
     *
     * The generator sanitises twice and puts its empty-string fallback BETWEEN the passes. The first
     * pass keeps Arabic (`\p{L}` is Unicode-aware), so the fallback sees a non-empty slug and never
     * fires; the second pass is ASCII-only and deletes every Arabic letter. Arabic is this product's
     * primary market, so in production this is the NORMAL case, not an edge one.
     *
     * When the fix lands, this test fails. Rewrite it to assert the corrected slug.
     */
    public function test_an_arabic_store_name_collapses_to_a_hyphen_which_is_the_defect(): void
    {
        $this->assertSame('-', $this->sanitise('متجر الشام'), 'store name 1');
        $this->assertSame('-', $this->sanitise('سوق دمشق'), 'store name 2');
        $this->assertSame('-2', $this->sanitise('متجر 2'), 'only the ASCII digit survives');
        $this->assertSame('', $this->sanitise('مـتجر!!!'), 'and this one reduces to the empty string');

        // the control: a Latin name is unaffected, so the cause is the ASCII pass and not the whole generator
        $this->assertSame('damascus-bazaar', $this->sanitise('Damascus Bazaar'));
    }

    /**
     * AUDIT SECTION 3.1, second limb -- so Arabic-named stores CONTEND for one slug.
     *
     * Driven through the real `User::slugs()` against a real table, because the contention is the
     * de-duplication's behaviour. Two differently-named Arabic stores receive `-` and `-1`: the public
     * URL is decided by signup order and carries no trace of the merchant's name.
     */
    public function test_two_differently_named_arabic_stores_contend_for_the_same_slug(): void
    {
        $user = $this->merchant();

        $first = User::slugs('متجر الشام');
        Store::create(['name' => 'متجر الشام', 'email' => $user->email, 'theme_id' => 'stylique', 'slug' => $first, 'created_by' => $user->id]);

        $second = User::slugs('سوق دمشق');

        $this->assertSame('-', $first);

        // `--1`, not `-1`: the `/-+/ => -` collapse runs BEFORE de-duplication, so appending `-1` to the
        // base slug `-` re-introduces the double hyphen the collapse exists to remove. Measured, not assumed.
        $this->assertSame('--1', $second, 'the second Arabic store is addressed as /--1');
        $this->assertNotSame($first, $second, 'premise: de-duplication did run');
    }

    /**
     * AUDIT SECTION 3.2 -- after 100 collisions the generator returns NULL. THIS IS A DEFECT.
     *
     * `User::slugs()` ends in a `for ($i = 1; $i <= 100; $i++)` with no fallback `return`, so a PHP
     * function that exhausts it yields null. Combined with 3.1 the threshold is 101 ARABIC-NAMED
     * stores, not 101 identically-named ones.
     *
     * Rather than create 101 stores, this drives the exhaustion directly: 101 rows whose slugs occupy
     * `-` and `-1` … `-100`, which is the state the 101st Arabic signup arrives at.
     */
    public function test_the_hundred_and_first_colliding_store_gets_a_null_slug_which_is_the_defect(): void
    {
        $user = $this->merchant();

        // the real derived set is `--1` … `--100` (see the test above), not `-1` … `-100`. Seeding the
        // wrong set is how this test first passed for the wrong reason: `--1` was still free, so the
        // generator returned it and the exhaustion was never reached.
        $taken = ['-'];
        for ($i = 1; $i <= 100; $i++) {
            $taken[] = '--' . $i;
        }
        foreach ($taken as $slug) {
            Store::create(['name' => 'متجر', 'email' => $user->email, 'theme_id' => 'stylique', 'slug' => $slug, 'created_by' => $user->id]);
        }

        $this->assertSame(101, Store::count(), 'premise: the 100 derived slugs and the base are all taken');
        $this->assertNull(User::slugs('متجر الشام'), 'the generator no longer returns a slug');
    }

    /**
     * AUDIT SECTION 3.2, the consequence -- and a correction to what this audit first claimed.
     *
     * The audit's first draft said the null slug of the previous test is STORED, and that `getStore()`
     * then fails open, because Laravel rewrites `where(col, null)` into `whereNull(col)` and production
     * runs MySQL with `strict => false`. **That was wrong, and this test is what showed it.** The
     * `stores.slug` column is `NOT NULL`, and non-strict mode does not waive a NOT NULL violation for an
     * explicit NULL in a single-row INSERT -- it waives implicit defaults and truncation. So the engine
     * REFUSES the row on both engines.
     *
     * The real consequence is therefore a hard failure, not a silent mis-resolution: the 101st
     * Arabic-named store cannot be created at all, and the merchant gets a 500. That is still a defect
     * worth fixing (audit stage 4), and it is a different one -- cheaper to detect and with no
     * cross-tenant consequence. Verified on real MySQL 8.0.46 by
     * tests/Support/Security/bin/verify-store-slug-identity-on-mysql.php, because an engine-dependent
     * claim is exactly what SQLite is least able to settle.
     */
    public function test_the_engine_refuses_a_null_slug_so_store_creation_fails_instead_of_failing_open(): void
    {
        $user = $this->merchant();

        $this->assertTrue(
            $this->columnIsNotNullable('stores', 'slug'),
            'premise: stores.slug is NOT NULL -- if it became nullable, the fail-open reading returns and this test must change'
        );

        $this->expectException(\Illuminate\Database\QueryException::class);
        Store::create(['name' => 'Nameless', 'email' => $user->email, 'theme_id' => 'stylique', 'slug' => null, 'created_by' => $user->id]);
    }

    /** whether a column rejects NULL, asked of the engine rather than of the migration file */
    private function columnIsNotNullable(string $table, string $column): bool
    {
        foreach (DB::select('pragma table_info(' . $table . ')') as $info) {
            if ($info->name === $column) {
                return (int) $info->notnull === 1;
            }
        }

        $this->fail("{$table}.{$column} does not exist");
    }

    /**
     * AUDIT SECTION 2 -- `stores.slug` carries no unique index, so uniqueness is advisory.
     *
     * The de-duplication in `User::slugs()` is a SELECT followed by an unguarded INSERT. This asserts
     * what the engine permits, which is what decides whether two concurrent signups can collide: the
     * duplicate is written and `getStore()` then resolves it to ONE of the two stores, silently.
     */
    public function test_the_database_accepts_two_stores_with_the_same_slug(): void
    {
        $user = $this->merchant();

        $a = Store::create(['name' => 'First', 'email' => $user->email, 'theme_id' => 'stylique', 'slug' => 'collide', 'created_by' => $user->id]);
        $b = Store::create(['name' => 'Second', 'email' => $user->email, 'theme_id' => 'stylique', 'slug' => 'collide', 'created_by' => $user->id]);

        $this->assertSame(2, DB::table('stores')->where('slug', 'collide')->count(), 'the engine refused the duplicate, so this measurement is void');

        $resolved = getStore('collide');
        $this->assertContains($resolved->id, [$a->id, $b->id]);
        $this->assertSame($a->id, $resolved->id, 'the lower-id store is served under both merchants\' URL');
    }

    /**
     * AUDIT SECTION 2 and 6.2 -- there is no permanent identifier column today.
     *
     * This is the ratchet for the audit's central claim. When a `store_number` column is added
     * (migration stage 1), this test fails and must be rewritten to assert the column's constraints
     * instead -- NOT NULL, UNIQUE, and absent from `$fillable`.
     */
    public function test_stores_carries_no_permanent_identifier_column_yet(): void
    {
        foreach (['store_number', 'subdomain', 'custom_domain', 'uuid', 'public_id'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('stores', $column),
                "stores.{$column} now exists -- rewrite this test to assert its constraints (audit section 6.2)"
            );
        }

        // and the identifier that DOES exist is the editable one
        $this->assertTrue(Schema::hasColumn('stores', 'slug'));
        $this->assertContains('slug', (new Store)->getFillable(), 'slug is mass-assignable, i.e. editable (audit section 3.4)');
    }
}
