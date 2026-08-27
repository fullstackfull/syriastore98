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
        if (!Schema::hasTable('lottery_settings')) {
            Schema::create('lottery_settings', function (Blueprint $table) {
                $table->id();
                $table->float('min_ticket')->default(0);
                $table->float('max_ticket_per_user')->default(0);
                $table->float('total_ticket')->default(0);
                $table->float('number_of_winner')->default(0);
                $table->date('lottery_start_date')->nullable();
                $table->date('lottery_end_date')->nullable();
                $table->time('lottery_start_time')->nullable();
                $table->time('lottery_end_time')->nullable();
                $table->string('lottery_products')->nullable();
                $table->string('refund_ticket')->nullable();
                $table->float('ticket_price')->default(0);
                $table->string('created_by');
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
        Schema::dropIfExists('lottery_settings');
    }
};
