<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkSellchaseApiKey extends Command
{
    protected $signature = 'sellchase:link-api-key {--user=1 : User ID to link}';

    protected $description = 'ربط SELLCHASE_API_KEY بالمستخدم المحدد';

    public function handle(): int
    {
        $apiKey = config('services.sellchase_api_key') ?: config('services.api_key');
        if (empty($apiKey)) {
            $this->error('SELLCHASE_API_KEY أو API_KEY غير موجود في .env');

            return 1;
        }

        $userId = (int) $this->option('user');
        $user = User::find($userId);
        if (! $user) {
            $this->error("المستخدم {$userId} غير موجود");

            return 1;
        }

        DB::table('users')->where('api_key', $apiKey)->update(['api_key' => null]);
        DB::table('users')->where('id', $userId)->update(['api_key' => $apiKey]);
        $this->info("تم ربط المفتاح بالمستخدم: {$user->name} (ID: {$userId})");

        return 0;
    }
}
