<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The two feed-posting plans. Idempotent by slug. Free trial caps posting at 3/month; the paid plan
 * is unlimited (post_limit_monthly = null). Price is informational until billing is wired.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(['slug' => 'free_trial'], [
            'name_en' => 'Free trial',
            'name_ar' => 'الباقة المجانية',
            'post_limit_monthly' => 3,
            'price' => 0,
            'currency' => 'EGP',
            'trial_days' => 30,
            'is_active' => true,
            'position' => 0,
        ]);

        Plan::updateOrCreate(['slug' => 'paid'], [
            'name_en' => 'Pro',
            'name_ar' => 'الباقة الاحترافية',
            'post_limit_monthly' => null, // unlimited
            'price' => 199,
            'currency' => 'EGP',
            'trial_days' => 0,
            'is_active' => true,
            'position' => 1,
        ]);
    }
}
