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
   Schema::create('store_devices', function (Blueprint $table) {
    $table->id();

    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('store_id');

    $table->text('fcm_token');
    $table->string('device_id')->nullable();
    $table->string('platform')->nullable();

    $table->boolean('is_active')->default(true);

    $table->timestamps();

    $table->index(['store_id', 'is_active']);
    $table->index(['user_id', 'store_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_devices');
    }
};