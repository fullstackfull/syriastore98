<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('product_additional_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('product_id');
            $table->string('field_id');
            $table->string('label');
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_additional_custom_fields');
    }
};
