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
        if (!Schema::hasTable('lottery_customers')) {
            Schema::create('lottery_customers', function (Blueprint $table) {
                $table->id();
                $table->string('lottery_id')->nullable();
                $table->string('ticket_id')->default(0);
                $table->string('customer_id')->nullable();
                $table->string('lottery_price')->nullable();
                $table->string('lottery_code')->nullable();
                $table->string('status')->nullable();
                $table->string('email_sent')->nullable();
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
        Schema::dropIfExists('lottery_customers');
    }
};
