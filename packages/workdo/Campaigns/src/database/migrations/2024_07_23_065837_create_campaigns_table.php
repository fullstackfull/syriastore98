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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('user')->nullable();
            $table->integer('store')->nullable();
            $table->integer('product')->nullable();
            $table->integer('total_days')->nullable();
            $table->double('total_cost')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('order_id')->nullable();
            $table->integer('status')->default(0);
            $table->integer('approval')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
