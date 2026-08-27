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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug');
            $table->string('image_url')->nullable();
            $table->string('image_path')->nullable();
            $table->string('icon_path')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->integer('trending')->default('0')->comment('0 => no, 1 => yes');
            $table->integer('status')->default('0')->comment('0 => Inactive, 1 => Active');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
