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
        if (!Schema::hasTable('order_reviews')) {
            Schema::create('order_reviews', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->float('rating')->default('0.00');
                $table->longText('description')->nullable();
                $table->unsignedBigInteger('order_id')->default(0);
                $table->unsignedBigInteger('product_order_id')->default(0);
                $table->unsignedBigInteger('store_id')->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_reviews');
    }
};
