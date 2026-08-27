<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStorageSettingToBadgeSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('badge_settings', function (Blueprint $table) {
            $table->string('storage_setting')->nullable(); 
            $table->integer('width')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('badge_settings', function (Blueprint $table) {
            $table->dropColumn('storage_setting');
            $table->integer('width')->nullable();
        });
    }
}
