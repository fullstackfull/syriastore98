<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('affiliate_settings')) {
            Schema::create('affiliate_settings', function (Blueprint $table) {
                $table->id();
                $table->integer('percentage');
                $table->integer('minimum_threshold_amount');
                $table->integer('is_enable')->default(0);
                $table->unsignedBigInteger('store_id')->nullable();
                $table->integer('created_by');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_settings');
    }
};
