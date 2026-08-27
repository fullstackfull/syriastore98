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
        if (!Schema::hasTable('spin_customers')) {
            Schema::create('spin_customers', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('is_used')->nullable();
                $table->string('winner_value')->nullable();
                $table->string('number')->nullable();
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
        Schema::dropIfExists('spin_customers');
    }
};
