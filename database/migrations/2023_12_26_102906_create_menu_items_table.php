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
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_id')->nullable();
            $table->morphs('menu_itemable');
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->onDelete(action: 'cascade');
            $table->string('target')->nullable();
            $table->integer('order')->default(0);
            $table->string('icon_type')->nullable();
            $table->longText('icon')->nullable();
            $table->timestamps();

            $table->foreign('menu_id')->references('id')->on('menus')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
