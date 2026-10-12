<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * COORDINATOR FIX (Phase 1B) — this migration was in no agent's file set and it is
     * what stopped `migrate:fresh` on SQLite, so no Feature test in the repository could
     * execute. Reproduced verbatim before the fix:
     *
     *   SQLSTATE[HY000]: General error: 1 Cannot add a PRIMARY KEY column
     *   (SQL: alter table "currency" add column "id" integer primary key autoincrement not null)
     *
     * SQLite cannot ALTER TABLE ADD a PRIMARY KEY column at all; the only way to give an
     * existing table a primary key is to rebuild it. The `currency` table is created
     * without an id by 2024_01_04_052630_create_currency_table.php (name, code, symbol).
     *
     * The MySQL/MariaDB path below is UNCHANGED, byte for byte, from the original. Only a
     * SQLite branch is added. That is why editing an already-applied migration is
     * acceptable here: the schema this produces on the production driver is identical, so
     * no deployed database diverges. MariaDB is listed explicitly because Laravel 11
     * reports 'mariadb' as a driver name distinct from 'mysql', and config/database.php
     * defines a separate mariadb connection in this very repository.
     */
    public function up(): void
    {
        if (Schema::hasColumn('currency', 'id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('currency', function (Blueprint $table) {
                $table->id()->first();
                $table->timestamp('created_at')->nullable()->after('symbol');
                $table->timestamp('updated_at')->nullable()->after('created_at');
            });

            return;
        }

        // SQLite (and any other driver): rebuild the table with the primary key in place,
        // preserving every existing row. Column order differs from the MySQL result, which
        // is immaterial — nothing in this codebase selects currency columns positionally.
        Schema::create('currency__rebuild', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->nullable();
            $table->string('code', 3)->nullable();
            $table->string('symbol', 5)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('currency')->orderBy('code')->chunk(500, function ($rows) {
            $payload = [];

            foreach ($rows as $row) {
                $payload[] = [
                    'name'   => $row->name ?? null,
                    'code'   => $row->code ?? null,
                    'symbol' => $row->symbol ?? null,
                ];
            }

            if ($payload !== []) {
                DB::table('currency__rebuild')->insert($payload);
            }
        });

        Schema::drop('currency');
        Schema::rename('currency__rebuild', 'currency');
    }

    public function down(): void
    {
        // Intentionally irreversible: the original migration had an empty down() and
        // dropping a primary key is not safely reversible on SQLite either.
    }
};
