<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\ProductFeeds;
use App\Support\Localization\LocaleContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductFeedController extends Controller
{
    use ResolvesStorefront;

    public function show(Request $request, string $channel, ProductFeeds $feeds, LocaleContext $locale): StreamedResponse
    {
        abort_unless(in_array($channel, ProductFeeds::CHANNELS, true), 404);

        return $feeds->download($this->currentStore($request), $channel, $locale->current());
    }
}
