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
        if (!Schema::hasTable('purchase_tickets')) {
            Schema::create('purchase_tickets', function (Blueprint $table) {
                $table->id();
                $table->string('lottery_id')->nullable();
                $table->string('lottery_code')->default(0);
                $table->string('customer_id')->nullable();
                $table->string('firstname')->nullable();
                $table->string('lastname')->nullable();
                $table->string('email')->nullable();
                $table->string('number')->nullable();
                $table->string('address')->nullable();
                $table->string('country_id')->nullable();
                $table->string('state_id')->nullable();
                $table->string('city_id')->nullable();
                $table->string('postcode')->nullable();
                $table->string('product_id')->nullable();
                $table->string('amount')->nullable();
                $table->string('payment_type')->nullable();
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
        Schema::dropIfExists('purchase_tickets');
    }
};
