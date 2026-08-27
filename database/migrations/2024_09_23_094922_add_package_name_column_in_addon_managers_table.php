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
        Schema::table('add_on_managers', function (Blueprint $table) {
            if (!Schema::hasColumn('add_on_managers', 'image')) {
                $table->text('image')->nullable();
            }
            if (!Schema::hasColumn('add_on_managers', 'is_enable')) {
                $table->boolean('is_enable')->default(0);
            }
            if (!Schema::hasColumn('add_on_managers', 'package_name')) {
                $table->string('package_name')->nullable();
            }
            if (!Schema::hasColumn('add_on_managers', 'is_display')) {
                $table->boolean('is_display')->default(1);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('add_on_managers', function (Blueprint $table) {
             // Add the logic to drop columns if needed
             if (Schema::hasColumn('add_on_managers', 'image')) {
                $table->dropColumn('image');
            }
            if (Schema::hasColumn('add_on_managers', 'is_enable')) {
                $table->dropColumn('is_enable');
            }
            if (Schema::hasColumn('add_on_managers', 'package_name')) {
                $table->dropColumn('package_name');
            }
            if (Schema::hasColumn('add_on_managers', 'is_display')) {
                $table->dropColumn('is_display');
            }
        });
    }
};
