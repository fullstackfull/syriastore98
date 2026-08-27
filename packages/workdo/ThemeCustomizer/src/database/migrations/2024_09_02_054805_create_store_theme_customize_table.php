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
        Schema::create('store_theme_customize', function (Blueprint $table) {
            $table->id();
            $table->longText('class_json')->nullable();
            $table->string('is_publish')->nullable();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('theme_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_theme_customize');
    }
};
