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
        if(!Schema::hasTable('pre_order_histories'))
        {
            Schema::create('pre_order_histories', function (Blueprint $table) {
                $table->id();
                $table->string('order_id')->nullable();
                $table->string('customer_id')->nullable();
                $table->string('order_email')->nullable();
                $table->string('mail_status')->nullable()->comment('0 => pending, 1 => send');
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
        Schema::dropIfExists('pre_order_histories');
    }
};
