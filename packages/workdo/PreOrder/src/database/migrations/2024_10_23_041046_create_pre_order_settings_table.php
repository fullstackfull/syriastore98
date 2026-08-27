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
        if(!Schema::hasTable('pre_order_settings'))
        {
            Schema::create('pre_order_settings', function (Blueprint $table) {
                $table->id();
                $table->string('product_id')->default(0);
                $table->string('variant_id')->default(0);
                $table->date('available_date')->nullable();
                $table->integer('qty')->default(0);
                $table->integer('status')->default(0)->comment('0 => off, 1 => on');
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
        Schema::dropIfExists('pre_order_settings');
    }
};
