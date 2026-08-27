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
        if (!Schema::hasTable('hubspot_product_services')) {
            Schema::create('hubspot_product_services', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('product_service_id')->nullable();
                $table->string('hubspot_product_service_id')->nullable();
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->integer('created_by');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hubspot_product_services');
    }
};
