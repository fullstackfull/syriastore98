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
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->default(0);
            $table->unsignedBigInteger('product_id');
            $table->integer('rating_no')->default('0');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->integer('status')->default('1')->comment('0 => inactive, 1 => active');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
