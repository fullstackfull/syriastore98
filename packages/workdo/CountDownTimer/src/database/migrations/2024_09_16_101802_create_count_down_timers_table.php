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
        if (!Schema::hasTable('count_down_timers')) {
            Schema::create('count_down_timers', function (Blueprint $table) {
                $table->id();
                $table->string('title')->nullable();
                $table->date('start_date')->nullable();
                $table->time('start_time')->nullable();
                $table->string('status')->default('off');
                $table->string('sale_image')->nullable();
                $table->string('image')->nullable();
                $table->string('theme_layout')->nullable();
                $table->string('description')->nullable();
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
        Schema::dropIfExists('count_down_timers');
    }
};
