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
        if (!Schema::hasColumn('customers', 'social_id') && !Schema::hasColumn('customers', 'social_type')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('social_id')->nullable()->after('created_by');
                $table->string('social_type')->nullable()->after('social_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop 'social_id' column if it exists
        if (Schema::hasColumn('customers', 'social_id') && Schema::hasColumn('customers', 'social_type')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('social_id');
                $table->dropColumn('social_type');
            });
        }
    }
};
