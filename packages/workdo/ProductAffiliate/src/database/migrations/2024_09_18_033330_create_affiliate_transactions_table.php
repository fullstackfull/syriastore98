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
        if (!Schema::hasTable('affiliate_transactions')) {
            Schema::create('affiliate_transactions', function (Blueprint $table) {
                $table->id();
                $table->integer('company_id');
                $table->integer('order_id');
                $table->integer('product_order_id');
                $table->decimal('final_price',15,2)->default(0.0);
                $table->integer('commission');
                $table->integer('affiliate_code')->default(0);
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
        Schema::dropIfExists('affiliate_transactions');
    }
};
