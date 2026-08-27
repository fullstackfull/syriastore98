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
        Schema::create('delivery_boys', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('profile_image')->nullable();
            $table->string('type')->nullable();
            $table->string('password')->nullable();
            $table->string('contact')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_boys');
    }
};
