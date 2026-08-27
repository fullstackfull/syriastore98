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
        Schema::table('', function (Blueprint $table) {
            if (!Schema::hasColumn('customers','social_id','social_type')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->string('social_id')->nullable()->after('store_id');
                    $table->string('social_type')->nullable()->after('social_id');
                });
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('customers','social_id','social_type')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('social_id');
                $table->dropColumn('social_type');
            });
         }
    }
};
