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
        Schema::create('emailbox_credentails', function (Blueprint $table) {
            $table->id();
            $table->string('emailbox_mail_driver')->nullable();
            $table->string('emailbox_mail_host')->nullable();
            $table->string('emailbox_outgoing_port')->nullable();
            $table->string('emailbox_incoming_port')->nullable();
            $table->string('emailbox_mail_username')->nullable();
            $table->string('emailbox_mail_from_address')->nullable();
            $table->string('emailbox_mail_password')->nullable();
            $table->string('emailbox_mail_encryption')->nullable();  
            $table->string('emailbox_mail_from_name')->nullable();  
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emailbox_credentails');
    }
};
