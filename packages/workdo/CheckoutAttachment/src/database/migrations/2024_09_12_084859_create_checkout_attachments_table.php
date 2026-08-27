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
        if (!Schema::hasTable('checkout_attachments')) {
            Schema::create('checkout_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_order_id')->nullable();
                $table->text('attachment')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
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
        Schema::dropIfExists('checkout_attachments');
    }
};
