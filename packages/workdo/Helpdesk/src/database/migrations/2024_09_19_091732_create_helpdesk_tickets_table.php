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
        if (!Schema::hasTable('helpdesk_tickets')) {
            Schema::create('helpdesk_tickets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ticket_id')->unique();
                $table->string('name');
                $table->string('email');
                $table->integer('category_id')->nullable();
                $table->integer('status_id')->nullable();
                $table->string('subject');
                $table->longText('attachments');
                $table->longText('description')->nullable();
                $table->longText('note')->nullable();
                $table->integer('user_id');
                $table->integer('business_id')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helpdesk_tickets');
    }
};
