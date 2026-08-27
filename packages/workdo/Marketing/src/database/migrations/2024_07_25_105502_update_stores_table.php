<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('stores')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->string('Company')->nullable();
                $table->text('industry')->nullable();
                $table->text('description')->nullable();
                $table->text('banner')->nullable();
                $table->text('logo')->nullable();
                $table->text('card_theme')->nullable();
                $table->string('theme_color')->nullable();
                $table->string('password')->nullable();
                $table->string('enable_password')->nullable();
                $table->integer('workspace')->nullable();
                $table->string('current_business')->nullable();
                $table->integer('vcard_type_id')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};