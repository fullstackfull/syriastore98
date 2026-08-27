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
        if (!Schema::hasTable('order_partial_payments')) {
            Schema::create('order_partial_payments', function (Blueprint $table) {
                $table->id();
                $table->string('order_id')->nullable();
                $table->string('customer_id')->nullable();
                $table->string('payment')->nullable();
                $table->string('payment_type')->nullable();
                $table->string('order_status')->nullable();
                $table->string('payment_amount')->nullable();
                $table->string('payment_status')->nullable();
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
        Schema::dropIfExists('order_partial_payments');
    }
};
