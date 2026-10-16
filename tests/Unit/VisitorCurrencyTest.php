<?php

namespace Tests\Unit;

use App\Services\Storefront\VisitorCurrency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VisitorCurrencyTest extends TestCase
{
    public function test_private_addresses_never_trigger_an_external_lookup(): void
    {
        Http::preventStrayRequests();
        $service = new VisitorCurrency;
        foreach ([null, 'invalid', '127.0.0.1', '10.0.0.1', '::1'] as $ip) {
            $this->assertNull($service->country($ip));
        }
        $this->assertSame('USD', $service->choose('EG', ['USD', 'EUR'], 'USD'));
        $this->assertSame('EUR', $service->choose('DE', ['USD', 'EUR'], 'USD'));
        Http::assertNothingSent();
    }

    public function test_rate_refresh_is_cached_and_failure_keeps_the_previous_snapshot(): void
    {
        Cache::flush();
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'base_code' => 'USD', 'rates' => ['USD' => 1, 'AED' => 3.67, 'EUR' => 0.9]], 200)]);
        $service = new VisitorCurrency;
        $rates = $service->rates();
        $this->assertSame(0.9, $rates['EUR']);
        $this->assertSame($rates, $service->rates());
        Http::assertSentCount(1);
        Cache::forever('storefront:public-fx:usd', ['rates' => $rates, 'fetched_at' => time() - 90000]);
        Cache::forget('storefront:public-fx:usd:refresh');
        Http::fake(['open.er-api.com/*' => Http::response([], 503)]);
        $this->assertSame($rates, $service->rates());
        Http::assertSentCount(1);
    }
}
