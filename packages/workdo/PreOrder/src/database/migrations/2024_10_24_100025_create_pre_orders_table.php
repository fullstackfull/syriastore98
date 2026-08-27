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
        if(!Schema::hasTable('pre_orders'))
        {
            Schema::create('pre_orders', function (Blueprint $table) {
                $table->id();
                $table->string('enable_pre_order')->default('off');
                $table->string('name')->default(0);
                $table->string('message')->default(0);
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
        Schema::dropIfExists('pre_orders');
    }
};
