<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    protected function transform($key, $value)
    {
        // A full document save must not silently rewrite untouched answers,
        // multiline text or static HTML. Keep ordinary API field trimming intact.
        if (request()->is('api/v1/my-store/catalog/products/*/landing-page', 'api/v1/stores/*/catalog/products/*/landing-page')
            && str_starts_with($key, 'document.sections.')
            && preg_match('/\.(title|description|text|html|css|alt)$/D', $key) === 1) {
            return $value;
        }

        if ($key === 'html' && request()->is('api/v1/my-store/head-code', 'api/v1/stores/*/head-code')) {
            return $value;
        }

        return parent::transform($key, $value);
    }

    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
    ];
}
