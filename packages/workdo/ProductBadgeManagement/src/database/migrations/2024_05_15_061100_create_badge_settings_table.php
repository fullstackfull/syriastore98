<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBadgeSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('badge_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('textcolor')->nullable();
            $table->string('bgcolor')->nullable();
            $table->string('badgetxt')->nullable();
            $table->integer('newerthan')->nullable();
            $table->integer('position_top')->nullable();
            $table->integer('position_left')->nullable();
            $table->integer('padding_top')->nullable();
            $table->integer('padding_bottom')->nullable();
            $table->integer('padding_left')->nullable();
            $table->integer('padding_right')->nullable();
            $table->integer('radius_top_left')->nullable();
            $table->integer('radius_top_right')->nullable();
            $table->integer('radius_bottom_left')->nullable();
            $table->integer('radius_bottom_right')->nullable();
            $table->float('opacity')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('badge_settings');
    }
}