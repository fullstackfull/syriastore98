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
        if (!Schema::hasTable('automatic_order_printings')) {
            Schema::create('automatic_order_printings', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->longText('value')->nullable();
                $table->string('created_by');
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
        Schema::dropIfExists('automatic_order_printings');
    }
};
