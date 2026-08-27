<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_notifications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('type', 50);
            $table->string('title');
            $table->text('message')->nullable();

            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->json('data')->nullable();

            $table->boolean('is_read')->default(false);
            $table->boolean('push_sent')->default(false);

            $table->timestamps();

            $table->index(['store_id', 'is_read']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_notifications');
    }
};