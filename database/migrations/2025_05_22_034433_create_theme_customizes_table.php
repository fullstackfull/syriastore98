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
        Schema::create('theme_customizes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->longText('value')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('theme_id')->nullable();
            $table->timestamps();
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theme_customizes');
    }
};
