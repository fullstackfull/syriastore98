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
        if (!Schema::hasTable('helpdesk_conversions')) {
            Schema::create('helpdesk_conversions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('ticket_id');
                $table->text('description');
                $table->text('attachments');
                $table->text('sender');
                $table->timestamps();
                $table->foreign('ticket_id')->references('id')->on('helpdesk_tickets')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helpdesk_conversions');
    }
};
