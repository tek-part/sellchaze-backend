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
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('product_id')->unsigned();
            $table->bigInteger('attribute_id')->unsigned();
            $table->timestamps();
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products');
        });

        Schema::table('product_attributes', function (Blueprint $table) {
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
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropForeign('product_attributes_product_id_foreign');
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropForeign('product_attributes_attribute_id_foreign');
        });

        Schema::dropIfExists('product_attributes');
    }
};
