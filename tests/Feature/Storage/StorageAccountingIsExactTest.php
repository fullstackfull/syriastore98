<?php

namespace Tests\Feature\Storage;

use App\Models\User;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE STORED FIGURE, NOT THE COMPUTED ONE -- because `users.storage_limit` IS A VARCHAR.
 *
 * `2014_10_12_000000_create_users_table.php:38` declares `$table->string('storage_limit')->nullable()`
 * and `User` casts it to nothing, so the column receives whatever PHP stringifies and hands back a
 * string on reload. A float calculation that is correct in memory therefore proves nothing about
 * what a merchant's account actually holds: every case here SAVES and RELOADS through the real
 * column, with `refresh()` so the assertion reads the database rather than the in-memory model.
 *
 * `User` is `Cachable` (CLAUDE.md: 79 of 95 models are), so the write goes through Eloquent `save()`
 * -- which flushes -- and the read is a fresh `refresh()`. A raw `DB::table()` write here would
 * leave Eloquent serving the pre-write row for the rest of the test, which CLAUDE.md records as
 * three measured false readings.
 *
 * WHAT FAILS BEFORE THE FIX: every case below except the isolation one. The old expression charged
 * `number_format($bytes / 1048576, 2)`, so a 1,024-byte upload stored `0.00` and the merchant was
 * charged nothing.
 */
class StorageAccountingIsExactTest extends TestCase
{
    use RefreshDatabase;

    /** Absolute paths this test created under `uploads/`, removed in tearDown. */
    private array $disposable = [];

    protected function tearDown(): void
    {
        foreach ($this->disposable as $path) {
            if (is_file($path)) {
                @unlink($path);
            }

            $dir = dirname($path);

            if (is_dir($dir) && (array_diff(scandir($dir) ?: [], ['.', '..']) === [])) {
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }

    private function merchant(?string $usage = null): User
    {
        $user = User::factory()->create([
            'type' => 'company',
            'storage_limit' => $usage,
        ]);

        $user->refresh();

        return $user;
    }

    /**
     * A plan with a storage limit.
     *
     * Created directly rather than with a factory because this project ships no `PlanFactory` --
     * measured, not assumed: the first draft of these two cases used `Plan::factory()` and died with
     * `Class "Database\Factories\PlanFactory" not found`. `plans.name` is unique and `plans.themes`
     * is a NOT NULL text column, so both are supplied.
     */
    private function planWithLimit(string $limit): \App\Models\Plan
    {
        return \App\Models\Plan::create([
            'name' => 'zz9-storage-' . $limit . '-' . uniqid(),
            'price' => 0,
            'duration' => 'Lifetime',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 1,
            'storage_limit' => $limit,
            'themes' => '[]',
        ]);
    }

    private function storedUsage(User $user): string
    {
        return (string) User::withoutGlobalScopes()->findOrFail($user->id)->storage_limit;
    }

    // ---------------------------------------------------------------------------------------------
    // the defect, through the real writer and the real column
    // ---------------------------------------------------------------------------------------------

    /** THE DEFECT: a small upload was charged nothing at all. */
    #[Test]
    public function test_a_small_upload_is_charged_in_the_database(): void
    {
        $merchant = $this->merchant();

        Utility::updateStorageLimit($merchant->id, 1024);

        $this->assertGreaterThan(
            0.0,
            (float) $this->storedUsage($merchant),
            'a 1 KB upload stored no usage at all, so a merchant has unlimited free storage in '
            . 'files of this size'
        );
    }

    /** AND MANY OF THEM ACCUMULATE, rather than each storing zero. */
    #[Test]
    public function test_many_small_uploads_accumulate_in_the_database(): void
    {
        $merchant = $this->merchant();

        for ($i = 0; $i < 50; $i++) {
            Utility::updateStorageLimit($merchant->id, 2048);
        }

        $stored = (float) $this->storedUsage($merchant);
        $exact = 50 * 2048 / 1048576;

        /*
         * ACCURATE TO WITHIN THE QUANTUM, IN EITHER DIRECTION -- AND THE FIRST DRAFT OF THIS
         * ASSERTION WAS WRONG IN A WAY WORTH KEEPING ON THE RECORD.
         *
         * It demanded `>= $exact`, i.e. that accumulation can never undercharge. That read as true
         * from the unit test, where 10,000 ONE-BYTE uploads each round 9.537e-7 UP to 1e-6. But
         * 2,048 bytes is 0.001953125 MiB, which rounds DOWN to 0.001953 -- so fifty of them stored
         * 0.09765 against an exact 0.09765625, and the "never undercharges" claim was accidental,
         * not a property. Rounding to a fixed number of decimals is NEAREST, so the error is
         * symmetric and bounded by half a quantum per write; it does not systematically favour
         * either side, which is the honest guarantee and is what is asserted.
         *
         * The property that actually matters -- that no file is charged NOTHING -- is separate and
         * is asserted on its own above.
         */
        $halfQuantumPerWrite = 50 * (0.5 / (10 ** 6));

        $this->assertLessThanOrEqual(
            $halfQuantumPerWrite,
            abs($stored - $exact),
            '50 uploads of 2 KB drifted further than rounding explains: stored ' . $stored
            . ' against an exact ' . $exact
        );

        $this->assertGreaterThan(0.09, $stored,
            'the accumulated figure is nowhere near 50 x 2 KB, so these uploads were not charged');
    }

    /** A fractional figure survives the round trip through the string column. */
    #[Test]
    public function test_a_fractional_figure_survives_save_and_reload(): void
    {
        $merchant = $this->merchant();

        Utility::updateStorageLimit($merchant->id, 1536);  // 0.00146484375 MiB

        $stored = $this->storedUsage($merchant);

        $this->assertDoesNotMatchRegularExpression('/[eE]/', $stored,
            'the column holds an exponent, which a database-side numeric cast truncates: ' . $stored);
        $this->assertStringNotContainsString(',', $stored,
            'the column holds a thousands separator: ' . $stored);
        $this->assertGreaterThan(0.0, (float) $stored);
    }

    /** A large figure is stored whole, not cut at a separator. */
    #[Test]
    public function test_a_large_figure_is_stored_whole(): void
    {
        $merchant = $this->merchant('1000.000000');

        Utility::updateStorageLimit($merchant->id, 1500 * 1048576);

        $stored = (float) $this->storedUsage($merchant);

        $this->assertGreaterThanOrEqual(
            2499.0,
            $stored,
            'a 1,500 MiB upload on top of 1,000 MiB did not reach 2,500 MiB: ' . $stored
        );
    }

    /**
     * A ONE-BYTE FILE DOES NOT STORE AN EXPONENT -- ADDED BECAUSE A MUTATION SURVIVED.
     *
     * `test_a_fractional_figure_survives_save_and_reload` drives 1,536 bytes, and PHP stringifies
     * 0.00146484375 as plain decimal -- so deleting the `StorageUsage::persist()` call from the
     * charge site broke nothing and the mutation SURVIVED. The exponent appears only for a figure
     * small enough to exceed PHP's `precision` of 14 significant digits, which in practice means a
     * file of a few bytes:
     *
     *     1 byte = 9.5367431640625E-7 MiB  ->  (string) "9.5367431640625E-7"
     *
     * That is the value the VARCHAR column would receive, and a database-side
     * `CAST(... AS DECIMAL)` stops at the `E`. So this case, and not the 1,536-byte one, is what
     * pins the persistence form at the real writer.
     */
    #[Test]
    public function test_a_one_byte_upload_does_not_store_an_exponent(): void
    {
        $merchant = $this->merchant();

        Utility::updateStorageLimit($merchant->id, 1);

        $stored = $this->storedUsage($merchant);

        $this->assertDoesNotMatchRegularExpression('/[eE]/', $stored,
            'a one-byte upload stored "' . $stored . '", which a database-side numeric cast '
            . 'truncates at the exponent');
        $this->assertGreaterThan(0.0, (float) $stored,
            'a one-byte upload was charged nothing');
    }

    // ---------------------------------------------------------------------------------------------
    // the credit-back, through the real deleter
    // ---------------------------------------------------------------------------------------------

    /**
     * A DELETION CREDITS BACK WHAT THE UPLOAD CHARGED -- ALSO ADDED BECAUSE A MUTATION SURVIVED.
     *
     * Nothing drove `Utility::changeStorageLimit()` at all, so reverting its arithmetic to the old
     * display-string form could not be caught. It needs a REAL file, because the method resolves
     * the path through `StoredUpload::resolveAll()` -- which requires `realpath()` to land inside
     * `base_path('uploads')` and to be a file -- and then DELETES it. The fixture is therefore
     * consumed by the method under test, which is why `tearDown()` tolerates its absence.
     *
     * The path deliberately has no numeric first segment: `StoredUpload::resolve()` applies its
     * cross-tenant folder rule only when the first segment `ctype_digit`, and tenant isolation is
     * not what this case is measuring.
     */
    #[Test]
    public function test_a_deletion_credits_back_what_the_upload_charged_in_the_database(): void
    {
        /*
         * PRIOR USAGE, AND THAT IS NOT INCIDENTAL -- A MUTATION SURVIVED WITHOUT IT.
         *
         * The first draft started the merchant at zero, so the credit-back landed at exactly zero
         * and `StorageUsage::persist()`'s clamp mapped BOTH the correct arithmetic and the old
         * display-string arithmetic to "0.000000". Reverting the credit-back therefore broke
         * nothing. Measured: `5.000000 + 1234567 bytes` is 6.177378 MiB, and crediting it back with
         * the old form subtracts the string "1.18" instead of 1.1773777, leaving 4.997378 -- which
         * a non-zero starting figure exposes and a zero one hides.
         */
        $merchant = $this->merchant('5.000000');

        $relative = 'uploads/zz9storage/' . uniqid('probe-') . '.bin';
        $absolute = base_path($relative);

        @mkdir(dirname($absolute), 0o777, true);
        file_put_contents($absolute, str_repeat('x', 1234567));
        $this->disposable[] = $absolute;

        $this->assertFileExists($absolute, 'premise: the credit-back needs a real file to size');

        Utility::updateStorageLimit($merchant->id, filesize($absolute));
        $charged = $this->storedUsage($merchant);
        $this->assertGreaterThan(6.0, (float) $charged, 'premise: the upload was charged on top of 5 MiB');

        Utility::changeStorageLimit($merchant->id, $relative);

        $this->assertSame(
            '5.000000',
            $this->storedUsage($merchant),
            'the credit-back took usage from ' . $charged . ' MiB to '
            . $this->storedUsage($merchant) . ' rather than back to 5.000000, so usage drifts on '
            . 'every upload-delete cycle'
        );
        $this->assertFileDoesNotExist($absolute, 'the method under test did not delete the file');
    }

    // ---------------------------------------------------------------------------------------------
    // the plan limit still holds
    // ---------------------------------------------------------------------------------------------

    /**
     * THE CONTROL AGAINST A FIX THAT STOPS ENFORCING THE QUOTA.
     *
     * `updateStorageLimit()` returns a translated error string when the plan limit is reached and
     * `1` otherwise. The shipped comparison is `<=`, so a total that exactly equals the limit is
     * refused; that is existing policy and is preserved deliberately.
     */
    #[Test]
    public function test_the_plan_limit_still_refuses_an_upload_that_exceeds_it(): void
    {
        $plan = $this->planWithLimit('10');
        $merchant = $this->merchant('9.500000');
        $merchant->plan_id = $plan->id;
        $merchant->save();

        $result = Utility::updateStorageLimit($merchant->id, 2 * 1048576);

        $this->assertNotSame(1, $result, 'an upload past the plan limit was accepted');
        $this->assertSame(
            '9.500000',
            $this->storedUsage($merchant),
            'a refused upload still moved the stored usage'
        );
    }

    /** And an upload inside the limit is still accepted. */
    #[Test]
    public function test_an_upload_inside_the_plan_limit_is_still_accepted(): void
    {
        $plan = $this->planWithLimit('100');
        $merchant = $this->merchant('1.000000');
        $merchant->plan_id = $plan->id;
        $merchant->save();

        $result = Utility::updateStorageLimit($merchant->id, 1048576);

        $this->assertSame(1, $result, 'a legitimate upload was refused, so this is an outage');
        $this->assertSame('2.000000', $this->storedUsage($merchant));
    }

    // ---------------------------------------------------------------------------------------------
    // tenancy
    // ---------------------------------------------------------------------------------------------

    /**
     * ONE MERCHANT'S UPLOAD DOES NOT TOUCH ANOTHER'S USAGE.
     *
     * `User` carries NO global scopes and is absent from `config/tenancy.php`'s `scoped_models`
     * (CLAUDE.md, re-measured by `ClaudeMdScopedModelClaimTest`), so nothing in the framework
     * prevents a storage writer from reaching the wrong row -- the isolation here rests entirely on
     * the handler using the id it was given. Asserted through `withoutGlobalScopes()` so the read
     * answers against the table rather than against whichever tenant happens to be resolved.
     */
    #[Test]
    public function test_one_merchants_upload_leaves_another_merchants_usage_alone(): void
    {
        $a = $this->merchant('1.000000');
        $b = $this->merchant('5.000000');

        Utility::updateStorageLimit($a->id, 3 * 1048576);

        $this->assertSame('4.000000', $this->storedUsage($a));
        $this->assertSame(
            '5.000000',
            $this->storedUsage($b),
            "merchant A's upload changed merchant B's recorded storage"
        );
    }
}
