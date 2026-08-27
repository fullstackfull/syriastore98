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
        Schema::table('stores', function (Blueprint $table) {
            $table->string('duo_setting_enabled')->nullable();
            $table->string('duo_api_host_name')->nullable();
            $table->string('duo_secret_key')->nullable();
            $table->string('duo_integration_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('duo_setting_enabled');
            $table->dropColumn('duo_api_host_name');
            $table->dropColumn('duo_secret_key');
            $table->dropColumn('duo_integration_key');
        });
    }
};
