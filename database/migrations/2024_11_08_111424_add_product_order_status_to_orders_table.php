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
        Schema::table('orders', function (Blueprint $table) {
            //
        });
        if (!Schema::hasColumn('orders', 'product_order_status')) {

            Schema::table('orders', function (Blueprint $table) {
                $table->string('product_order_status')->nullable()->after('return_price');
                $table->string('lottery_code')->nullable()->after('product_order_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            //
        });
    }
};
