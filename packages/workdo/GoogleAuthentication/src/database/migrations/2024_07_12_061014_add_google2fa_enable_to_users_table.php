<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        if (!Schema::hasColumn('users', 'google2fa_enable'))
        {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('google2fa_enable')->default(0)->after('is_enable_login');
                $table->text('google2fa_secret')->nullable()->after('is_enable_login');
            });
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

        });
    }
};
