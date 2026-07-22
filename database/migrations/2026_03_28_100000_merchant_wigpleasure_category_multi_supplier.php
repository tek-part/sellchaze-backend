<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchant_wigpleasure_category_suppliers')) {
            return;
        }

        Schema::table('merchant_wigpleasure_category_suppliers', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
            $table->dropForeign(['supplier_user_id']);
        });

        Schema::table('merchant_wigpleasure_category_suppliers', function (Blueprint $table) {
            $table->dropUnique('mwcs_merchant_category_unique');
        });

        Schema::table('merchant_wigpleasure_category_suppliers', function (Blueprint $table) {
            $table->unique(
                ['merchant_id', 'wigpleasure_category_id', 'supplier_user_id'],
                'mwcs_merchant_category_supplier_unique'
            );
            $table->foreign('merchant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('supplier_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('merchant_wigpleasure_category_suppliers')) {
            return;
        }

        Schema::table('merchant_wigpleasure_category_suppliers', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
            $table->dropForeign(['supplier_user_id']);
        });

        Schema::table('merchant_wigpleasure_category_suppliers', function (Blueprint $table) {
            $table->dropUnique('mwcs_merchant_category_supplier_unique');
        });

        Schema::table('merchant_wigpleasure_category_suppliers', function (Blueprint $table) {
            $table->unique(['merchant_id', 'wigpleasure_category_id'], 'mwcs_merchant_category_unique');
            $table->foreign('merchant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('supplier_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
