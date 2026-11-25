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

        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);
        $slug = preg_replace('/\s+/', '-', trim($slug));
        $slug = strtolower($slug);

        /* STAGE 4 MOVED THIS BELOW THE ASCII PASS, and the copy follows. While it sat above, it
           could never fire for an Arabic name: it tested a string that was not yet empty, and the
           pass that emptied it ran afterwards. `uniqid()` in the original; the BRANCH, not the
           value, is what this copy needs to reproduce. */
        if (! preg_match('/[a-z0-9]/', $slug)) {
            $slug = 'store-FIXED-FOR-TEST';
        }

        return $slug;
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

        /*
         * ARABIC NAMES ARE IN THIS LIST NOW, and their absence was a real blind spot. The guard drove
         * only ASCII names, so when stage 4 moved the fallback the copy silently diverged for exactly
         * the case this whole file is about -- and the section 3.1 test went on passing against a
         * copy that no longer described the shipped generator. A guard that cannot see the file's own
         * subject is not a guard.
         *
         * The `store-` cases compare the SHAPE, not the value: the real generator ends in `uniqid()`
         * and the copy in a fixed string, so only a literal comparison of the prefix is meaningful.
         */
        foreach (['Damascus Bazaar', 'Shop One', 'a-b', 'Store 42'] as $name) {
            $this->assertSame(
                $this->sanitise($name),
                User::slugs($name),
                "the local copy diverges from User::slugs() for [{$name}], so every measurement below is unsound"
            );
        }

        foreach (['متجر الشام', 'سوق دمشق', 'مـتجر!!!'] as $name) {
            $this->assertSame(
                'store-FIXED-FOR-TEST',
                $this->sanitise($name),
                "the local copy no longer routes [{$name}] to the fallback"
            );
            $this->assertMatchesRegularExpression(
                '/^store-[a-z0-9]+$/',
                (string) User::slugs($name),
                "User::slugs() no longer routes [{$name}] to the fallback, so the copy and the "
                . 'shipped generator disagree about the case this file exists to measure'
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
    public function test_the_ascii_pass_still_strips_arabic_and_the_fallback_now_rescues_it(): void
    {
        /*
         * THE CAUSE IS UNCHANGED AND STILL WORTH PINNING. The ASCII-only pass
         * (`/[^a-zA-Z0-9\s-]/`, no `/u`) deletes every Arabic letter, so the sanitisation really
         * does reduce these names to nothing usable. Stage 4 did not change that -- it changed what
         * happens next. Measuring the passes separately from the rescue is what makes the
         * difference legible: if someone later "fixes" the ASCII pass instead, this test says so.
         */
        $this->assertSame('-2', $this->sanitiseWithoutFallback('متجر 2'), 'only the ASCII digit survives the passes');
        $this->assertSame('-', $this->sanitiseWithoutFallback('متجر الشام'), 'the passes leave only the space-hyphen');
        $this->assertSame('', $this->sanitiseWithoutFallback('مـتجر!!!'), 'and this one reduces to the empty string');

        /* AND THE SHIPPED GENERATOR NO LONGER HANDS ANY OF THOSE OUT. */
        foreach (['متجر الشام', 'سوق دمشق', 'مـتجر!!!'] as $name) {
            $this->assertMatchesRegularExpression('/^store-[a-z0-9]+$/', (string) User::slugs($name),
                'the generator handed out an unusable slug for ' . $name);
        }

        /* `متجر 2` keeps a digit, so it is USABLE and must NOT be replaced -- the fallback fires on
           "no alphanumeric character at all", not on "contains Arabic". A merchant who named their
           store with a number keeps it. */
        $this->assertSame('-2', User::slugs('متجر 2'),
            'a name that kept an alphanumeric character was replaced anyway, so the fallback is '
            . 'firing too widely and is discarding part of the merchant\'s own name');

        // the control: a Latin name is unaffected, so the cause is the ASCII pass and not the whole generator
        $this->assertSame('damascus-bazaar', $this->sanitise('Damascus Bazaar'));
    }

    /**
     * The two sanitisation passes WITHOUT the stage-4 fallback, so a test can measure the passes and
     * the rescue separately. Kept beside `sanitise()` rather than folded into it: conflating the two
     * is what let the section 3.1 pin go stale.
     */
    private function sanitiseWithoutFallback(string $data): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $data);
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);
        $slug = preg_replace('/\s+/', '-', trim($slug));

        return strtolower($slug);
    }

    /**
     * AUDIT SECTION 3.1, second limb -- REWRITTEN 2026-11-25 WHEN STAGE 4 LANDED, which is exactly
     * what this file's header says to do: "when the audit's stage 4 fixes land, these tests FAIL and
     * must be rewritten to the corrected expectation -- which is the intended signal, not an
     * accident." It failed; this is the rewrite.
     *
     * WHAT IT USED TO ASSERT, and it was true: two differently-named Arabic stores received `-` and
     * `--1`, so the public URL was decided by signup order and carried no trace of the merchant's
     * name. (`--1` and not `-1`, because the `/-+/ => -` collapse runs BEFORE de-duplication, so
     * appending `-1` to the base slug `-` re-introduced the double hyphen the collapse exists to
     * remove. Measured then, and worth keeping in the record.)
     *
     * WHAT CHANGED: `User::slugs()` now runs its unusable-slug fallback AFTER the ASCII pass instead
     * of before it, so a name that sanitises to no alphanumeric character at all gets
     * `'store-' . uniqid()`. The audit authorised this directly -- "Stage 4 -- fix the two slug
     * defects that survive regardless ... they should be fixed whether or not numeric subdomains
     * ship" -- and section 8 reserves only the numeric-subdomain questions for the owner.
     *
     * The assertion is now the PROPERTY rather than a literal: each store gets its own routable
     * slug. The literal would be a `uniqid()`, which is not assertable and should not be.
     */
    public function test_two_differently_named_arabic_stores_each_get_their_own_slug(): void
    {
        $user = $this->merchant();

        $first = User::slugs('متجر الشام');
        Store::create(['name' => 'متجر الشام', 'email' => $user->email, 'theme_id' => 'stylique', 'slug' => $first, 'created_by' => $user->id]);

        $second = User::slugs('سوق دمشق');

        foreach (['first' => $first, 'second' => $second] as $which => $slug) {
            $this->assertMatchesRegularExpression('/^store-[a-z0-9]+$/', (string) $slug, sprintf(
                'the %s Arabic store got the slug "%s". Stage 4 routes a name holding no '
                . 'alphanumeric character to the generator\'s own `store-` . uniqid() fallback.',
                $which,
                $slug
            ));
        }

        $this->assertNotSame($first, $second,
            'two differently-named Arabic stores still share one slug, which is the defect stage 4 '
            . 'exists to remove');
        $this->assertNotSame($first . '-1', $second,
            'the second store got the first store\'s slug with a collision suffix, so both are '
            . 'still really the same slug -- the contention was renamed, not removed');
    }

    /**
     * AUDIT SECTION 3.2 -- REWRITTEN 2026-11-25 WHEN STAGE 4 LANDED. It used to assert that after 100
     * collisions `User::slugs()` returned NULL, because the `for ($i = 1; $i <= 100; $i++)` loop had
     * no fallback `return` and a PHP function that falls off the end yields null.
     *
     * THE CONSEQUENCE THE AUDIT MEASURED, and corrected its own first draft over: an explicit NULL
     * into the `NOT NULL` `slug` column is refused under BOTH `strict => true` and `strict => false`,
     * so it was never a silent `whereNull` fail-open -- it was a HARD FAILURE. The 101st colliding
     * store could not be created and the merchant got a 500.
     *
     * The loop now ends in `return 'store-' . uniqid();`. `uniqid()` rather than a 101st suffix,
     * because a wider loop only moves the wall, and the fallback is the one this function already
     * uses for an unusable slug, so nothing new is invented.
     *
     * The exhaustion is still driven directly rather than by creating 101 stores, and the seeded set
     * is still `--1` … `--100`: section 3.1's collapse is what made that the real derived set, and
     * seeding `-1` … `-100` instead is how this test first passed for the wrong reason -- `--1` was
     * free, so the generator returned it and the exhaustion was never reached.
     */
    public function test_the_hundred_and_first_colliding_store_still_gets_a_routable_slug(): void
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

        $slug = User::slugs('متجر الشام');

        $this->assertNotNull($slug,
            'the generator returned null after exhausting its suffixes. stores.slug is NOT NULL and '
            . 'the engine refuses an explicit null on both strict settings, so this is a 500 at '
            . 'signup rather than a bad slug.');
        $this->assertMatchesRegularExpression('/^store-[a-z0-9]+$/', (string) $slug);
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
