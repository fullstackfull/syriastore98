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
        if(!Schema::hasTable('reward_club_points')){
            Schema::create('reward_club_points', function (Blueprint $table) {
                $table->id();
                if (Schema::hasTable('customers')) {
                    $table->unsignedBigInteger('customer_id')->nullable();
                    $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                } else {
                    $table->unsignedBigInteger('customer_id')->nullable();
                }
                if (Schema::hasTable('orders')) {
                    $table->unsignedBigInteger('order_id')->nullable();
                    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
                } else {
                    $table->unsignedBigInteger('order_id')->nullable();
                }
                $table->string('product_id')->default(0);
                $table->float('points',15,2)->default(0);
                $table->float('used_points',15,2)->default(0);
                $table->float('reward_price',15,2)->default(0);
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
        Schema::dropIfExists('reward_club_points');
    }
};
