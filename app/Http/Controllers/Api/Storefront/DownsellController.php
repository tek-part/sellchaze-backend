<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\DownsellOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DownsellController extends Controller
{
    use ResolvesStorefront;

    public function index(Request $request, DownsellOffers $offers): JsonResponse
    {
        $data = $request->validate(['product_ids' => ['sometimes', 'array', 'max:100'], 'product_ids.*' => ['integer', 'min:1'], 'product_slug' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $offers->publicOffer($this->currentStore($request), $data['product_ids'] ?? [], $data['product_slug'] ?? null)], 200, ['Cache-Control' => 'private, no-store']);
    }
}
