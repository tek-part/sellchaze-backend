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
        Schema::create('order_quotations', function (Blueprint $table) {
            $table->id();
            $table->integer('price')->unsigned();
            $table->date('delivery_date');
            $table->text('notes')->nullable();
            $table->string("status")->default('pending');
            $table->boolean('seen')->default(0);
            $table->unsignedBigInteger('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->unsignedBigInteger('supplier_user_id');
            $table->foreign('supplier_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('customer_user_id');
            $table->foreign('customer_user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_quotations');
    }
};
