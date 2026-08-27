<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('card_payments')) {
            Schema::create('card_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->float('payment_amount', 15, 2)->default(0);
                $table->text('content')->nullable();
                $table->string('payment_status', 100)->nullable();
                $table->string('payment_type')->nullable();
                $table->integer('is_enabled')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('card_payments');
    }
};