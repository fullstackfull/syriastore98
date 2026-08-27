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
        if (!Schema::hasTable('auction_product_orders')) {
            Schema::create('auction_product_orders', function (Blueprint $table) {
                $table->id();
                $table->string('product_order_id')->nullable();
                $table->dateTime('order_date')->nullable();
                $table->integer('customer_id')->default(0);
                $table->text('product_json')->nullable();
                $table->string('product_id')->default(0);
                $table->float('price')->nullable()->default(0);
                $table->float('tax_price')->nullable()->default(0);
                $table->float('shipping_price')->nullable()->default(0);
                $table->float('sub_total')->nullable()->default(0);
                $table->string('payment_status')->nullable();
                $table->string('order_status')->nullable();
                $table->integer('delivery_id')->default(0);
                $table->text('delivery_comment')->nullable();
                $table->integer('delivered_status')->default(0)->comment('0=>pending/1=>diliver/2=>cancel/3=>return');
                $table->date('confirmed_date')->nullable();
                $table->date('delivery_date')->nullable();
                $table->date('picked_date')->nullable();
                $table->date('shipped_date')->nullable();
                $table->date('cancel_date')->nullable();
                $table->float('reward_points', 8, 2)->default(0);
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
        Schema::dropIfExists('auction_product_orders');
    }
};
