<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\ProductAbTests;
use Illuminate\Http\Request;

class AbTestController extends Controller
{
    use ResolvesStorefront;

    public function resolve(Request $request, ProductAbTests $tests)
    {
        $data = $request->validate(['visitor' => ['required', 'uuid']]);

        return response()->json(['data' => $tests->resolve($this->currentStore($request), (string) $request->route('slug'), $data['visitor'])], 200, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
