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
        if (!Schema::hasTable('auction_product_bids')) {
            Schema::create('auction_product_bids', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->float('bid_amount')->default(0);
                $table->integer('customer_id');
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->timestamps();
            });
        }
    
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auction_product_bids');
    }
};
