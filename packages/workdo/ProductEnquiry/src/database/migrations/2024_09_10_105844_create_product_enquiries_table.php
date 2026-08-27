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
        if (!Schema::hasTable('product_enquiries')) {
            Schema::create('product_enquiries', function (Blueprint $table) {
                $table->id();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('email');
                $table->string('contact')->nullable();
                $table->longText('comment')->nullable();
                $table->longText('answers')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_enquiries');
    }
};
