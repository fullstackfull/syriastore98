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
        if (!Schema::hasTable('store_locators')) {
            Schema::create('store_locators', function (Blueprint $table) {
                $table->id();
                $table->string('store_locator_enabled')->nullable();
                $table->string('store_locator_api_key')->nullable();
                $table->string('store_address')->nullable();
                $table->string('pincode')->nullable();
                $table->string('store_longitude')->nullable();
                $table->string('store_latitude')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
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
        Schema::dropIfExists('store_locators');
    }
};
