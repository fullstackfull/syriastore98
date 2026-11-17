<?php

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * THE CONSENT TABLES, as migrated. D8 / MKT-008.
 *
 * Dev and test only: the migrations behind these tables have NOT been applied to production, and
 * applying them needs the owner's separate authorisation. This file asserts the structure the code
 * relies on, and that the `down()` of each migration cannot destroy evidence by accident.
 */
class ConsentSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        'marketing_consent_events' => '2026_11_18_000100_create_marketing_consent_events_table.php',
        'marketing_contact_states' => '2026_11_18_000200_create_marketing_contact_states_table.php',
        'marketing_unsubscribe_tokens' => '2026_11_18_000300_create_marketing_unsubscribe_tokens_table.php',
    ];

    public function test_the_tables_exist_with_the_columns_the_code_reads(): void
    {
        $this->assertTrue(Schema::hasColumns('marketing_consent_events', [
            'id', 'store_id', 'channel', 'purpose', 'address_hash', 'address_hint', 'customer_id', 'event',
            'source', 'evidence', 'actor_type', 'actor_id', 'occurred_at', 'created_at',
        ]));
        $this->assertTrue(Schema::hasColumns('marketing_contact_states', [
            'id', 'store_id', 'channel', 'purpose', 'address_hash', 'status', 'last_event_id', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('marketing_unsubscribe_tokens', [
            'id', 'store_id', 'channel', 'purpose', 'address_hash', 'address_hint', 'customer_id', 'token_hash', 'created_at', 'used_at',
        ]));
    }

    public function test_the_events_table_has_no_updated_at_because_a_row_is_never_updated(): void
    {
        $this->assertFalse(Schema::hasColumn('marketing_consent_events', 'updated_at'));
    }

    public function test_no_table_holds_a_raw_address_column(): void
    {
        foreach (array_keys(self::MIGRATIONS) as $table) {
            foreach (['email', 'phone', 'mobile', 'address', 'token'] as $forbidden) {
                $this->assertFalse(Schema::hasColumn($table, $forbidden),
                    $table.' has a column named '.$forbidden.': consent is keyed on a hash and the token is stored hashed');
            }
        }
    }

    public function test_the_projection_cannot_hold_two_answers_for_one_contact(): void
    {
        $row = [
            'store_id' => 1, 'channel' => 'email', 'purpose' => 'marketing', 'address_hash' => str_repeat('a', 64),
            'status' => 'granted', 'last_event_id' => 1,
        ];

        DB::table('marketing_contact_states')->insert($row);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('marketing_contact_states')->insert($row);
    }

    public function test_a_token_hash_is_unique(): void
    {
        $row = [
            'store_id' => 1, 'channel' => 'email', 'purpose' => 'marketing', 'address_hash' => str_repeat('a', 64),
            'token_hash' => str_repeat('b', 64),
        ];

        DB::table('marketing_unsubscribe_tokens')->insert($row);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('marketing_unsubscribe_tokens')->insert($row);
    }

    public function test_none_of_the_tables_has_a_foreign_key(): void
    {
        // The house shape for evidence tables (`audit_logs`, `theme_publication_events`): an evidence log
        // that the thing it concerns can cascade away is not an evidence log.
        foreach (array_keys(self::MIGRATIONS) as $table) {
            $this->assertSame([], Schema::getForeignKeys($table), $table.' must carry no foreign key');
        }
    }

    public function test_down_refuses_to_drop_a_table_that_holds_data(): void
    {
        DB::table('marketing_consent_events')->insert([
            'store_id' => 1, 'channel' => 'email', 'purpose' => 'marketing', 'address_hash' => str_repeat('c', 64),
            'event' => 'granted', 'source' => 'test', 'occurred_at' => now(), 'created_at' => now(),
        ]);
        DB::table('marketing_contact_states')->insert([
            'store_id' => 1, 'channel' => 'email', 'purpose' => 'marketing', 'address_hash' => str_repeat('c', 64),
            'status' => 'granted', 'last_event_id' => 1,
        ]);
        DB::table('marketing_unsubscribe_tokens')->insert([
            'store_id' => 1, 'channel' => 'email', 'purpose' => 'marketing', 'address_hash' => str_repeat('c', 64),
            'token_hash' => str_repeat('d', 64),
        ]);

        foreach (self::MIGRATIONS as $table => $file) {
            $migration = require database_path('migrations/'.$file);

            try {
                $migration->down();
                $this->fail('down() dropped a populated '.$table);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Refusing to drop', $e->getMessage());
            }

            $this->assertTrue(Schema::hasTable($table), $table.' was dropped despite holding data');
        }
    }

    public function test_up_is_safe_to_run_again_on_the_tables_it_created(): void
    {
        foreach (self::MIGRATIONS as $table => $file) {
            $migration = require database_path('migrations/'.$file);

            $migration->up();

            $this->assertTrue(Schema::hasTable($table));
        }
    }
}
