<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\Commerce\StoreHeadCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectStoreHeadCode
{
    public function __construct(private readonly StoreHeadCode $code) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $store = $request->attributes->get('store');
        if (! $store instanceof Store || $response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }
        $publication = $this->code->publication($store, $request);
        $content = $response->getContent();
        if (! $publication || ! is_string($content) || ! preg_match('/<\/head\s*>/i', $content)) {
            return $response;
        }
        $marker = '<meta name="sellchaze-head-code" content="'.$publication['fingerprint'].'">';
        $html = preg_replace_callback('/<\/head\s*>/i', fn () => $marker."\n".$publication['html']."\n</head>", $content, 1) ?? $content;
        $response->setContent($html);
        $response->setEtag(hash('sha256', $html));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
