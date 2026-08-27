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
        Schema::create('shopify_conections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('module')->nullable();
            $table->bigInteger('shopify_id');
            $table->integer('original_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_conections');
    }
};
