<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->text('value');
            $table->bigInteger('attribute_id')->unsigned();
            $table->timestamps();
        });

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->foreign('attribute_id')->references('id')->on('attributes');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropForeign('attribute_values_attribute_id_foreign');
        });

        Schema::dropIfExists('attribute_values');
    }
};
