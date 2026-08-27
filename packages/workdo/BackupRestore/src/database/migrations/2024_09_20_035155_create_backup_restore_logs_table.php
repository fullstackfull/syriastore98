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
        if (!Schema::hasTable('backup_restore_logs'))
        {
            Schema::create('backup_restore_logs', function (Blueprint $table) {
                $table->id();
                $table->string('file_name');
                $table->string('type');
                $table->integer('created_by');
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
        Schema::dropIfExists('backup_restore_logs');
    }
};
