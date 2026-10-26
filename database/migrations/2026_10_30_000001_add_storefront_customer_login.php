<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2 customer login: storefront customers may sign in with a WhatsApp code or Google, so an account
 * can exist without an e-mail address or password. Verified identities are unique per store.
 * Login methods are off for every store until the merchant enables them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('customer_login_configuration')->nullable();
        });
        Schema::table('store_customers', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('phone_normalized', 32)->nullable();
            $table->string('google_sub', 255)->nullable();
            $table->unique(['store_id', 'phone_normalized'], 'store_customers_phone_identity');
            $table->unique(['store_id', 'google_sub'], 'store_customers_google_identity');
        });
        Schema::table('store_phone_challenges', function (Blueprint $table) {
            $table->string('purpose', 12)->default('checkout');
        });
        Schema::create('store_customer_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->constrained('store_customers')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('verifier_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_customer_login_codes');
        Schema::table('store_phone_challenges', fn (Blueprint $table) => $table->dropColumn('purpose'));
        // Accounts created without e-mail/password keep working rows; give them non-routable values first.
        DB::table('store_customers')->whereNull('email')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('store_customers')->where('id', $row->id)->update(['email' => 'customer-'.$row->id.'@account.invalid']);
            }
        });
        DB::table('store_customers')->whereNull('password')->update(['password' => '']);
        Schema::table('store_customers', function (Blueprint $table) {
            $table->dropUnique('store_customers_phone_identity');
            $table->dropUnique('store_customers_google_identity');
            $table->dropColumn(['phone_normalized', 'google_sub']);
        });
        Schema::table('store_customers', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('customer_login_configuration'));
    }
};
