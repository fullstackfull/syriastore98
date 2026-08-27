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
        if (!Schema::hasTable('recently_viewed_products')) {
            Schema::create('recently_viewed_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('default_time')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
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
        Schema::dropIfExists('recently_viewed_products');
    }
};
