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
        if (!Schema::hasTable('bundle_products')) {
            Schema::create('bundle_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('bundle_products')->nullable();
                $table->integer('created_by');
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
        Schema::dropIfExists('bundle_products');
    }
};
