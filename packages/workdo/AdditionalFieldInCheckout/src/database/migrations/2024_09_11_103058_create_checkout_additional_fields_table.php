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
        if (!Schema::hasTable('checkout_additional_fields')) {
            Schema::create('checkout_additional_fields', function (Blueprint $table) {
                $table->id();
                $table->string('order_id')->nullable();
                $table->string('field_name')->nullable();
                $table->string('value')->nullable();
                $table->string('status')->nullable();
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
        Schema::dropIfExists('checkout_additional_fields');
    }
};
