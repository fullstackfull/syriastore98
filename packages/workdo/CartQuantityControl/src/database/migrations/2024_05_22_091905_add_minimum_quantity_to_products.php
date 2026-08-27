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
        if (!Schema::hasColumn('products', 'minimum_quantity') && !Schema::hasColumn('products', 'maximum_quantity')) {
            Schema::table('products', function (Blueprint $table) {
                $table->integer('minimum_quantity')->default(1)->after('product_stock');
                $table->integer('maximum_quantity')->default(1)->after('minimum_quantity');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            //
        });
    }
};
