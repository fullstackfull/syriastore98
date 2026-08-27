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
        if(Schema::hasTable('carts'))
        {
            Schema::table('carts', function (Blueprint $table) {
                if (!Schema::hasColumn('carts', 'order_type')) {
                    $table->string('order_type')->nullable()->after('price');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'order_type')) {
                $table->dropColumn('order_type');
            }
        });
    }
};
