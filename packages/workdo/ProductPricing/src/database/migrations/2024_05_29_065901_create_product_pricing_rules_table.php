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
        if (!Schema::hasTable('product_pricing_rules')) {
            Schema::create('product_pricing_rules', function (Blueprint $table) {
                $table->id();
                $table->string('pricing_method')->nullable();
                $table->string('quantity_type')->nullable();
                $table->text('field_json')->nullable();
                $table->text('product_json')->nullable();
                $table->text('product_get_json')->nullable();
                $table->text('conditions')->nullable();
                $table->string('rule_status')->nullable();
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
        Schema::dropIfExists('product_pricing_rules');
    }
};
