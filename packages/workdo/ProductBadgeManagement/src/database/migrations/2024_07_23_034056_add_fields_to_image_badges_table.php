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
        Schema::table('image_badges', function (Blueprint $table) {
            $table->integer('position_top')->nullable();
            $table->integer('position_left')->nullable();
            $table->integer('padding_top')->nullable();
            $table->integer('padding_bottom')->nullable();
            $table->integer('padding_left')->nullable();
            $table->integer('padding_right')->nullable();
            $table->integer('radius_top_left')->nullable();
            $table->integer('radius_top_right')->nullable();
            $table->integer('radius_bottom_left')->nullable();
            $table->integer('radius_bottom_right')->nullable();
            $table->integer('width')->nullable();
            $table->float('opacity', 3, 2)->nullable(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_badges', function (Blueprint $table) {
            $table->integer('position_top')->nullable();
            $table->integer('position_left')->nullable();
            $table->integer('padding_top')->nullable();
            $table->integer('padding_bottom')->nullable();
            $table->integer('padding_left')->nullable();
            $table->integer('padding_right')->nullable();
            $table->integer('radius_top_left')->nullable();
            $table->integer('radius_top_right')->nullable();
            $table->integer('radius_bottom_left')->nullable();
            $table->integer('radius_bottom_right')->nullable();
            $table->integer('width')->nullable();
            $table->float('opacity', 3, 2)->nullable();
        });
    }
};
