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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id')->nullable();
            $table->string('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->string('attachment')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->integer('created_by');
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
