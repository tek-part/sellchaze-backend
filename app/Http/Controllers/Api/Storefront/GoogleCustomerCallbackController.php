<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Commerce\CustomerGoogleLogin;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * G2: the single Google redirect URI for every storefront (on the API host). Only a signed, unexpired
 * state whose origin still resolves to its store is ever redirected to; the storefront receives a
 * one-time code in the URL fragment, which servers and referrers never see.
 */
class GoogleCustomerCallbackController extends Controller
{
    public function __invoke(Request $request, CustomerGoogleLogin $google): Response
    {
        $state = $google->state($request->query('state'));
        $store = $state ? $google->store($state) : null;
        if (! $state || ! $store) {
            return response('This sign-in link is invalid or has expired. Return to the store and try again.', 400, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 2048 || $request->query('error') !== null) {
            return $this->back($state['o'], null);
        }
        try {
            return $this->back($state['o'], $google->complete($store, $state, $code));
        } catch (RuntimeException) {
            // Rejected identity (token checks, inactive or conflicting account): a calm retry message.
            return $this->back($state['o'], null);
        } catch (Throwable $error) {
            report($error);

            return $this->back($state['o'], null);
        }
    }

    private function back(string $origin, ?string $code): Response
    {
        $target = $code !== null ? $origin.'/login/google#code='.$code : $origin.'/login?google=failed';

        return redirect()->away($target)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
