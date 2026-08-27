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
        if (!Schema::hasTable('additional_fields')) {
            Schema::create('additional_fields', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('type')->nullable();
                $table->string('field_title')->nullable();
                $table->string('label')->nullable();
                $table->string('class')->nullable();
                $table->string('placeholder')->nullable();
                $table->string('default_value')->nullable();
                $table->string('options')->nullable();
                $table->unsignedBigInteger('store_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('additional_fields');
    }
};
