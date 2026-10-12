<?php

namespace Tests\Support\Fixtures;

use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Builds two COMPLETE, INDEPENDENT tenants so cross-tenant authorization can be tested.
 *
 * WHY TWO. Almost every tenancy bug in this codebase is of the shape "the handler trusts
 * an id from the request instead of scoping it to the caller's store". You cannot catch
 * that with one tenant: a single-tenant test passes whether or not any scoping exists.
 * Every fixture set here therefore yields tenant A and tenant B, each with its own
 * merchant, store, customer and order, so a test can authenticate as A and reach for B's
 * record -- the exact shape of the P0 findings.
 *
 * NO REAL DATA, EVER. Every value is synthetic and deterministic: emails are on
 * `example.test` (an RFC 6761 reserved TLD that can never resolve), names are literal
 * fixture labels, and passwords are a fixed throwaway constant. Nothing here is copied
 * from any production dump, and nothing here is a credential for anything that exists.
 *
 * DEFENSIVE BY DESIGN. Agents 1-3 are changing models, policies and scopes concurrently.
 * This class therefore resolves model classes by name at call time and asserts their
 * presence with a clear message, rather than importing them at the top and failing at
 * class load. A fixture that cannot build says why.
 *
 * Deliberately NOT a Laravel factory in database/factories/. Factories there are not in
 * this agent's ownership and would collide; these are plain builders under tests/Support/
 * so agents 1-3 can use them from their own test directories with no shared-file risk.
 *
 * See docs/development/phase-1/05-REGRESSION-TEST-PLAN.md.
 */
final class TenantFixtures
{
    /**
     * A fixed, obviously-synthetic password. Not a secret, not in use anywhere, and
     * shared by every fixture user so tests can authenticate without inventing one.
     */
    public const PASSWORD = 'fixture-only-not-a-real-password';

    /** Reserved TLD (RFC 6761): guaranteed never to resolve. */
    private const EMAIL_DOMAIN = 'example.test';

    /**
     * Builds one tenant: a merchant user, a store they own, a customer of that store and
     * an order for that customer.
     *
     * @param string $label a short tag distinguishing this tenant in assertion output
     *                      (for example 'a' and 'b'). Appears in every generated value,
     *                      so a failure message names which tenant leaked.
     *
     * @return array{label: string, merchant: object, store: object, customer: object, order: object}
     */
    public static function tenant(string $label): array
    {
        $label = preg_replace('/[^a-z0-9]/i', '', $label) ?: 'x';
        $label = strtolower($label);

        $merchant = self::make('App\Models\User', [
            'name'              => "FIXTURE merchant {$label}",
            'email'             => "merchant-{$label}@" . self::EMAIL_DOMAIN,
            'password'          => Hash::make(self::PASSWORD),
            'type'              => 'company',
            'email_verified_at' => now(),
            'is_active'         => 1,
            'lang'              => 'en',
        ]);

        $store = self::make('App\Models\Store', [
            'name'       => "FIXTURE store {$label}",
            'email'      => "store-{$label}@" . self::EMAIL_DOMAIN,
            'slug'       => "fixture-store-{$label}",
            'created_by' => $merchant->id,
            'is_active'  => 1,
            // NOT NULL with no default in the live schema
            // (2023_12_14_090259_create_stores_table.php). Set to the default theme the
            // application ships rather than left blank, so a fixture store renders.
            'theme_id'   => 'default',
        ]);

        // The merchant's "current store" is what tenant-scoped handlers read. Set it so
        // an authenticated fixture merchant has a coherent tenant context.
        self::fillIfPresent($merchant, ['current_store' => $store->id]);

        $customer = self::make('App\Models\Customer', [
            'first_name' => 'FIXTURE',
            'last_name'  => "customer {$label}",
            'email'      => "customer-{$label}@" . self::EMAIL_DOMAIN,
            'password'   => Hash::make(self::PASSWORD),
            'store_id'   => $store->id,
            'created_by' => $merchant->id,
            'status'     => 1,
            // billable_id/billable_type are NOT NULL with no default in the live schema,
            // a polymorphic pair left over from the billing integration. A customer
            // cannot be inserted without them, so the fixture points them at the owning
            // store -- the only coherent choice available at fixture time.
            'billable_id'   => $store->id,
            'billable_type' => 'App\Models\Store',
        ]);

        // ORDER FIXTURES ARE CONDITIONAL, and that is a finding, not a convenience.
        //
        // App\Models\Order::booted() (app/Models/Order.php:63-80) unconditionally writes
        // `currency_code` and `currency_symbol` on every `creating` event, and lists both
        // as fillable (:26-27). NO MIGRATION ANYWHERE CREATES THOSE COLUMNS -- not in
        // database/migrations/, not in any of the 69 module migration directories; a grep
        // for `currency_code` across every migration in both trees returns nothing. So a
        // schema built from migrations cannot accept an Order insert at all:
        //   SQLSTATE[HY000]: table orders has no column named currency_code
        // The production MySQL database evidently has the columns, added outside the
        // migration set, which is why this is invisible in production and fatal in a
        // fresh environment.
        //
        // Rather than fail every consumer with that cryptic error, the order fixture is
        // skipped when the schema cannot hold it, and the reason is carried back so a
        // test can mark itself BLOCKED with the finding id instead of silently passing.
        // See NEW-orders-currency-columns-missing in the Phase 1B Agent 5 report; the fix
        // is one additive migration and belongs to Agent 2.
        $orderBlockedReason = self::orderSchemaGap();

        $order = $orderBlockedReason !== null ? null : self::make('App\Models\Order', [
            'product_order_id' => "FIXTURE-ORDER-{$label}",
            'order_date'       => now(),
            'customer_id'      => $customer->id,
            'store_id'         => $store->id,
            'created_by'       => $merchant->id,
            'is_guest'         => 0,
            // Monetary columns are `float` in the current schema (see
            // 2024_01_23_061635_create_orders_table.php). Fixtures use values that are
            // exactly representable in binary floating point, so a fixture can never be
            // the reason a monetary assertion fails -- that must only ever be the code
            // under test. Agent 3's reconciliation tests supply their own awkward values
            // on purpose.
            'product_price'    => 100.00,
            'delivery_price'   => 0.00,
            'tax_price'        => 0.00,
            'coupon_price'     => 0.00,
            'final_price'      => 100.00,
            'payment_type'     => 'cod',
            'payment_status'   => 'pending',
            'delivered_status' => 0,
        ]);

        return [
            'label'                => $label,
            'merchant'             => $merchant,
            'store'                => $store,
            'customer'             => $customer,
            'order'                => $order,
            'order_blocked_reason' => $orderBlockedReason,
        ];
    }

    /**
     * Why an Order cannot be inserted against the current schema, or null if it can.
     *
     * Checked against the live column list rather than hardcoded, so this resolves itself
     * the moment Agent 2's additive migration lands -- no test change required.
     */
    public static function orderSchemaGap(): ?string
    {
        if (!class_exists('App\Models\Order')) {
            return 'App\Models\Order does not exist.';
        }

        /** @var \Illuminate\Database\Eloquent\Model $order */
        $order   = new \App\Models\Order();
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($order->getTable());

        if ($columns === []) {
            return sprintf(
                "table '%s' has no columns -- migrations did not run for this connection.",
                $order->getTable()
            );
        }

        $writtenByModel = ['currency_code', 'currency_symbol'];
        $absent         = array_values(array_diff($writtenByModel, $columns));

        if ($absent === []) {
            return null;
        }

        return sprintf(
            "BLOCKED by NEW-orders-currency-columns-missing: App\\Models\\Order::booted() "
            . "(app/Models/Order.php:63-80) writes %s on every create, but the '%s' table "
            . 'has no such column and no migration in either tree creates it. An Order '
            . 'cannot be inserted against a migration-built schema. Fix is one additive '
            . 'migration (Agent 2); this check clears itself once it lands.',
            implode(' and ', array_map(static fn ($c) => "`{$c}`", $absent)),
            $order->getTable()
        );
    }

    /**
     * The standard two-tenant arrangement.
     *
     * @return array{a: array<string, mixed>, b: array<string, mixed>}
     */
    public static function twoTenants(): array
    {
        return [
            'a' => self::tenant('a'),
            'b' => self::tenant('b'),
        ];
    }

    /**
     * A user with no store and no elevated type: the "authenticated but entitled to
     * nothing" case, for insufficient-permission tests.
     */
    public static function unprivilegedUser(string $label = 'nobody'): object
    {
        return self::make('App\Models\User', [
            'name'              => "FIXTURE unprivileged {$label}",
            'email'             => "unprivileged-{$label}@" . self::EMAIL_DOMAIN,
            'password'          => Hash::make(self::PASSWORD),
            'type'              => 'customer',
            'email_verified_at' => now(),
            'is_active'         => 1,
        ]);
    }

    /**
     * Creates a model, writing only the attributes its table actually has.
     *
     * The schema carries columns that have been added, renamed and duplicated over many
     * migrations, and the five agents are changing models concurrently. Filtering by the
     * live column list means a fixture does not break the moment an unrelated column
     * moves, and an genuinely missing model gives a readable error instead of a
     * class-not-found deep inside a test.
     *
     * @param array<string, mixed> $attributes
     */
    private static function make(string $class, array $attributes): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException(
                "TenantFixtures cannot build {$class}: the class does not exist. If a model "
                . 'was moved or renamed, update tests/Support/Fixtures/TenantFixtures.php '
                . 'rather than working around it in individual tests.'
            );
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $class();
        $table = $model->getTable();

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);

        if ($columns === []) {
            throw new RuntimeException(
                "TenantFixtures cannot build {$class}: table '{$table}' has no columns, so "
                . 'the migrations did not run for this connection. The suite must run '
                . 'against sqlite :memory: with RefreshDatabase -- see tests/bootstrap.php.'
            );
        }

        $known = array_intersect_key($attributes, array_flip($columns));

        // Timestamps are set explicitly where the table has them, because several tables
        // in this schema declare created_at/updated_at as nullable with no default and
        // some models disable timestamps.
        foreach (['created_at', 'updated_at'] as $ts) {
            if (in_array($ts, $columns, true) && !array_key_exists($ts, $known)) {
                $known[$ts] = now();
            }
        }

        // Turn the schema's NOT NULL columns into an actionable message instead of a raw
        // "NOT NULL constraint failed" from PDO fifteen frames deep. This schema has
        // accumulated required columns with no defaults over many migrations
        // (stores.theme_id, customers.billable_id/billable_type), so an agent adding a
        // fixture for a new model will hit this -- and should be told exactly which
        // columns to supply.
        $unsatisfied = array_values(array_diff(
            self::requiredColumns($table),
            array_keys($known)
        ));

        if ($unsatisfied !== []) {
            throw new RuntimeException(sprintf(
                "TenantFixtures cannot build %s: table '%s' requires these columns, which "
                . "are NOT NULL with no default and were not supplied: %s.\nAdd them to "
                . 'the attribute list in tests/Support/Fixtures/TenantFixtures.php. Use '
                . 'an obviously-synthetic value -- never anything copied from production.',
                $class,
                $table,
                implode(', ', $unsatisfied)
            ));
        }

        $model->forceFill($known);
        $model->save();

        return $model;
    }

    /**
     * The columns of $table that are NOT NULL, have no default, and are not the primary
     * key -- that is, the columns an INSERT must supply.
     *
     * Driver-specific because there is no portable way to ask. Only sqlite is
     * implemented, which is sufficient and correct: the suite is pinned to sqlite
     * :memory: by tests/bootstrap.php and will not run on anything else. On any other
     * driver this returns an empty list, so the check simply does not fire and the
     * underlying database error stands -- it never guesses wrong.
     *
     * @return array<int, string>
     */
    private static function requiredColumns(string $table): array
    {
        $connection = \Illuminate\Support\Facades\Schema::getConnection();

        if ($connection->getDriverName() !== 'sqlite') {
            return [];
        }

        $required = [];

        foreach ($connection->select('PRAGMA table_info(' . $connection->getPdo()->quote($table) . ')') as $column) {
            $isNotNull   = (int) $column->notnull === 1;
            $hasDefault  = $column->dflt_value !== null;
            $isPrimary   = (int) $column->pk !== 0;

            if ($isNotNull && !$hasDefault && !$isPrimary) {
                $required[] = $column->name;
            }
        }

        return $required;
    }

    /**
     * Sets attributes on an existing model only where the column exists, then saves.
     *
     * @param array<string, mixed> $attributes
     */
    private static function fillIfPresent(object $model, array $attributes): void
    {
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($model->getTable());
        $known   = array_intersect_key($attributes, array_flip($columns));

        if ($known === []) {
            return;
        }

        $model->forceFill($known);
        $model->save();
    }
}
