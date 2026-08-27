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
        if (!Schema::hasColumn('product_variants', 'minimum_quantity') && !Schema::hasColumn('product_variants', 'maximum_quantity')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->integer('minimum_quantity')->default(1)->after('variation_price');
                $table->integer('maximum_quantity')->default(1)->after('minimum_quantity');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            //
        });
    }
};
