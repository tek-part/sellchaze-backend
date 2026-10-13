<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    protected function transform($key, $value)
    {
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
