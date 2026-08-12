<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subscription has used SoftDeletes since the billing model was expanded,
        // but the legacy migration never added the physical column.
        $this->ensureSubscriptionColumns();
        $this->ensurePlanColumns();

        if (! Schema::hasTable('entitlements')) {
            Schema::create('entitlements', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('kind', 16); // feature | quota
                $table->string('unit', 32)->nullable();
                $table->string('name_en');
                $table->string('name_ar');
                $table->text('description_en')->nullable();
                $table->text('description_ar')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('plan_entitlements')) {
            Schema::create('plan_entitlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->foreignId('entitlement_id')->constrained()->cascadeOnDelete();
                $table->boolean('value_boolean')->nullable();
                $table->unsignedBigInteger('value_integer')->nullable(); // null on an existing quota row = unlimited
                $table->string('value_text')->nullable();
                $table->timestamps();
                $table->unique(['plan_id', 'entitlement_id']);
            });
        }

        if (! Schema::hasTable('plan_prices')) {
            Schema::create('plan_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->string('country_code', 2)->nullable(); // null = global fallback
                $table->string('currency', 8);
                $table->string('billing_cycle', 16); // monthly | yearly
                $table->decimal('amount', 12, 2);
                $table->boolean('tax_inclusive')->default(false);
                $table->boolean('quote_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['plan_id', 'country_code', 'currency', 'billing_cycle'], 'plan_prices_market_unique');
            });
        }

        if (! Schema::hasTable('add_ons')) {
            Schema::create('add_ons', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name_en');
                $table->string('name_ar');
                $table->foreignId('entitlement_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('increment');
                $table->decimal('price_monthly', 12, 2)->default(0);
                $table->string('currency', 8)->default('EGP');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('organization_add_ons')) {
            Schema::create('organization_add_ons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('add_on_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('quantity')->default(1);
                $table->string('status', 20)->default('active');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'add_on_id']);
            });
        }

        $this->seedReferenceCatalog();
    }

    private function ensureSubscriptionColumns(): void
    {
        $columns = [
            'billing_cycle' => fn (Blueprint $table) => $table->string('billing_cycle', 16)->default('monthly'),
            'current_period_start' => fn (Blueprint $table) => $table->timestamp('current_period_start')->nullable(),
            'current_period_end' => fn (Blueprint $table) => $table->timestamp('current_period_end')->nullable(),
            'cancelled_at' => fn (Blueprint $table) => $table->timestamp('cancelled_at')->nullable(),
            'gateway_slug' => fn (Blueprint $table) => $table->string('gateway_slug')->nullable(),
            'gateway_subscription_id' => fn (Blueprint $table) => $table->string('gateway_subscription_id')->nullable(),
            'gateway_customer_id' => fn (Blueprint $table) => $table->string('gateway_customer_id')->nullable(),
            'last_invoice_id' => fn (Blueprint $table) => $table->unsignedBigInteger('last_invoice_id')->nullable(),
            'metadata' => fn (Blueprint $table) => $table->json('metadata')->nullable(),
            'deleted_at' => fn (Blueprint $table) => $table->softDeletes(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('subscriptions', $name)) {
                Schema::table('subscriptions', $definition);
            }
        }
    }

    private function ensurePlanColumns(): void
    {
        $columns = [
            // Some legacy production databases predate the canonical plans
            // migration even though they already contain a plans table.
            'post_limit_monthly' => fn (Blueprint $table) => $table->unsignedInteger('post_limit_monthly')->nullable(),
            'price' => fn (Blueprint $table) => $table->decimal('price', 10, 2)->default(0),
            'currency' => fn (Blueprint $table) => $table->string('currency', 8)->default('EGP'),
            'trial_days' => fn (Blueprint $table) => $table->unsignedInteger('trial_days')->default(0),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'position' => fn (Blueprint $table) => $table->unsignedInteger('position')->default(0),
            'description_en' => fn (Blueprint $table) => $table->text('description_en')->nullable(),
            'description_ar' => fn (Blueprint $table) => $table->text('description_ar')->nullable(),
            'target' => fn (Blueprint $table) => $table->string('target', 40)->default('company'),
            'price_monthly' => fn (Blueprint $table) => $table->decimal('price_monthly', 12, 2)->default(0),
            'price_yearly' => fn (Blueprint $table) => $table->decimal('price_yearly', 12, 2)->default(0),
            'is_featured' => fn (Blueprint $table) => $table->boolean('is_featured')->default(false),
            'sort_order' => fn (Blueprint $table) => $table->unsignedInteger('sort_order')->default(0),
            'features' => fn (Blueprint $table) => $table->json('features')->nullable(),
            'quotas' => fn (Blueprint $table) => $table->json('quotas')->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('plans', $name)) {
                Schema::table('plans', $definition);
            }
        }
    }

    private function seedReferenceCatalog(): void
    {
        $now = now();
        $plans = [
            'trial' => ['Trial', 'تجريبية', 0, 0, 30, false, 10],
            'starter' => ['Starter', 'البداية', 499, 4990, 0, false, 20],
            'growth' => ['Growth', 'النمو', 1499, 14990, 0, true, 30],
            'scale' => ['Scale', 'التوسع', 0, 0, 0, false, 40],
        ];
        foreach ($plans as $slug => [$en, $ar, $monthly, $yearly, $trialDays, $featured, $order]) {
            DB::table('plans')->updateOrInsert(['slug' => $slug], [
                'name_en' => $en,
                'name_ar' => $ar,
                'description_en' => "Sellchaze {$en} company plan",
                'description_ar' => "باقة {$ar} للشركات في Sellchaze",
                'target' => 'company',
                'post_limit_monthly' => null,
                'price' => $monthly,
                'price_monthly' => $monthly,
                'price_yearly' => $yearly,
                'currency' => 'EGP',
                'trial_days' => $trialDays,
                'is_active' => true,
                'is_featured' => $featured,
                'position' => $order,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('plans')->whereIn('slug', ['free_trial', 'paid'])->update(['is_active' => false, 'updated_at' => $now]);

        $definitions = [
            'business_network' => ['feature', null, 'Business network', 'شبكة الأعمال'],
            'rfqs' => ['feature', null, 'RFQs and quotations', 'طلبات وعروض الأسعار'],
            'storefront' => ['feature', null, 'Independent stores', 'المتاجر المستقلة'],
            'advanced_themes' => ['feature', null, 'Advanced themes', 'الثيمات المتقدمة'],
            'custom_domains' => ['feature', null, 'Custom domains', 'النطاقات المخصصة'],
            'analytics' => ['feature', null, 'Advanced analytics', 'التحليلات المتقدمة'],
            'api_access' => ['feature', null, 'API access', 'الوصول إلى API'],
            'audit_logs' => ['feature', null, 'Audit logs', 'سجلات التدقيق'],
            'advanced_permissions' => ['feature', null, 'Advanced permissions', 'الصلاحيات المتقدمة'],
            'priority_support' => ['feature', null, 'Priority support', 'الدعم المميز'],
            'stores' => ['quota', 'store', 'Stores', 'المتاجر'],
            'seats' => ['quota', 'seat', 'Team seats', 'مقاعد الفريق'],
            'posts_monthly' => ['quota', 'post/month', 'Monthly posts', 'المنشورات الشهرية'],
            'storage_gb' => ['quota', 'GB', 'Storage', 'التخزين'],
            'messages_monthly' => ['quota', 'message/month', 'Monthly messages', 'الرسائل الشهرية'],
        ];
        foreach ($definitions as $key => [$kind, $unit, $en, $ar]) {
            DB::table('entitlements')->updateOrInsert(['key' => $key], [
                'key' => $key, 'kind' => $kind, 'unit' => $unit,
                'name_en' => $en, 'name_ar' => $ar, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $matrix = [
            'trial' => ['business_network' => true, 'rfqs' => true, 'storefront' => true, 'stores' => 1, 'seats' => 3, 'posts_monthly' => 3, 'storage_gb' => 1, 'messages_monthly' => 100],
            'starter' => ['business_network' => true, 'rfqs' => true, 'storefront' => true, 'stores' => 1, 'seats' => 5, 'posts_monthly' => 100, 'storage_gb' => 10, 'messages_monthly' => 1000],
            'growth' => ['business_network' => true, 'rfqs' => true, 'storefront' => true, 'advanced_themes' => true, 'custom_domains' => true, 'analytics' => true, 'stores' => 5, 'seats' => 25, 'posts_monthly' => null, 'storage_gb' => 100, 'messages_monthly' => 10000],
            'scale' => ['business_network' => true, 'rfqs' => true, 'storefront' => true, 'advanced_themes' => true, 'custom_domains' => true, 'analytics' => true, 'api_access' => true, 'audit_logs' => true, 'advanced_permissions' => true, 'priority_support' => true, 'stores' => null, 'seats' => null, 'posts_monthly' => null, 'storage_gb' => null, 'messages_monthly' => null],
        ];
        foreach ($matrix as $planSlug => $values) {
            $planId = DB::table('plans')->where('slug', $planSlug)->value('id');
            foreach ($values as $key => $value) {
                $entitlement = DB::table('entitlements')->where('key', $key)->first();
                DB::table('plan_entitlements')->updateOrInsert([
                    'plan_id' => $planId,
                    'entitlement_id' => $entitlement->id,
                ], [
                    'value_boolean' => $entitlement->kind === 'feature' ? (bool) $value : null,
                    'value_integer' => $entitlement->kind === 'quota' ? $value : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach ($plans as $slug => $values) {
            $planId = DB::table('plans')->where('slug', $slug)->value('id');
            foreach (['monthly' => $values[2], 'yearly' => $values[3]] as $cycle => $amount) {
                DB::table('plan_prices')->updateOrInsert([
                    'plan_id' => $planId, 'country_code' => null, 'currency' => 'EGP', 'billing_cycle' => $cycle,
                ], [
                    'amount' => $amount,
                    'quote_required' => $slug === 'scale', 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        foreach ([['extra_store', 'Extra store', 'متجر إضافي', 'stores', 1, 299], ['extra_seat_5', 'Five team seats', 'خمسة مقاعد فريق', 'seats', 5, 199], ['storage_50gb', '50 GB storage', 'تخزين 50 جيجابايت', 'storage_gb', 50, 149]] as [$key, $en, $ar, $entitlementKey, $increment, $price]) {
            DB::table('add_ons')->updateOrInsert(['key' => $key], [
                'name_en' => $en, 'name_ar' => $ar,
                'entitlement_id' => DB::table('entitlements')->where('key', $entitlementKey)->value('id'),
                'increment' => $increment, 'price_monthly' => $price, 'currency' => 'EGP',
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_add_ons');
        Schema::dropIfExists('add_ons');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('entitlements');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'description_en', 'description_ar', 'target', 'price_monthly', 'price_yearly',
                'is_featured', 'sort_order', 'features', 'quotas',
            ]);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'billing_cycle', 'current_period_start', 'current_period_end', 'cancelled_at',
                'gateway_slug', 'gateway_subscription_id', 'gateway_customer_id', 'last_invoice_id', 'metadata',
            ]);
            $table->dropSoftDeletes();
        });
    }
};
