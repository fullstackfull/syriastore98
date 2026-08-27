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
        if (!Schema::hasTable('appointment_deatails')) {
            Schema::create('appointment_deatails', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('date')->nullable();
                $table->string('time')->nullable();
                $table->string('status')->default('pending');
                $table->text('note')->nullable();
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
        Schema::dropIfExists('appointment_deatails');
    }
};
