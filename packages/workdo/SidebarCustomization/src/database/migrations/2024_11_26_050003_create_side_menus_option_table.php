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
        Schema::create('side_menus_option', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('menu_type')->nullable();
            $table->string('type')->nullable();
            $table->string('name')->nullable();
            $table->string('icon')->nullable();
            $table->string('link_type')->nullable();
            $table->string('link')->nullable();
            $table->integer('position')->nullable()->default('0');
            $table->string('parent_id')->nullable();
            $table->integer('order')->default(0)->index();
            $table->string('window_type')->nullable();
            $table->string('is_enable')->default('off');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('side_menus_option');
    }
};
