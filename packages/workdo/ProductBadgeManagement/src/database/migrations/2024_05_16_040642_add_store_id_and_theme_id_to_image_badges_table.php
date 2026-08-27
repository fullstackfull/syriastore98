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
        Schema::table('image_badges', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->index();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_badges', function (Blueprint $table) {

        });
    }
};
