<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('pixel_fields')) {
            Schema::create('pixel_fields', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->string('platform')->nullable();
                $table->string('pixel_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pixel_fields');
    }
};