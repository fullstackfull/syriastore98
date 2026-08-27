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
        if (!Schema::hasTable('partial_payments')) {
            Schema::create('partial_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('enable_deposit')->nullable();
                $table->string('manage_store_wise')->nullable();
                $table->string('force_deposit')->nullable();
                $table->string('deposit_type')->nullable();
                $table->float('deposit_amount')->default(0);
                $table->unsignedBigInteger('store_id')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partial_payments');
    }
};
