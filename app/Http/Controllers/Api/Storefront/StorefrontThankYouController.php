<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StorefrontProductResource;
use App\Services\Storefront\StorefrontService;
use App\Services\Storefront\ThankYouPage;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontThankYouController extends Controller
{
    use ResolvesStorefront;

    /** Public presentation only: no order, receipt token, payment or customer data. */
    public function show(Request $request, ThankYouPage $page, StorefrontService $catalog, LocaleContext $locale): JsonResponse
    {
        $config = $page->publicConfiguration($this->currentStore($request), $locale->current());
        $config['products'] = $config['category'] ? StorefrontProductResource::collection($catalog->products($config['category']['slug'], 8)->getCollection()) : [];

        return response()->json(['data' => $config], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
    }
}
