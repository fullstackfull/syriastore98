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
        Schema::create('delivery_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('title')->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->integer('postcode')->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->integer('default_address')->default(0)->comment('0 => no / 1 => yes');
            $table->integer('type')->default(0)->comment('0 => home / 1 => work / 2 => other');
            $table->unsignedBigInteger('store_id')->nullable()->index();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
            $table->foreign('state_id')->references('id')->on('states')->onDelete('cascade');
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_addresses');
    }
};
