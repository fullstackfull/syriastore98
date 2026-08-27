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
        Schema::create('additional_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('label');
            $table->string('type'); // Field type (Text, Date, etc.)
            $table->json('options')->nullable(); // Options as JSON (for Radio, Select, Checkbox)
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('additional_custom_fields');
    }
};
