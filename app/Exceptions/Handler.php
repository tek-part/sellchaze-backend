<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        QuotaExceededException::class,
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (NotFoundHttpException $e, Request $request) {
            if (config('settings.FRONTEND_THEME', 'rizz') === 'growtech') {
                return response()->view('pages.growtech.404', [
                    'bodyClass' => 'not-found',
                    'pageCss' => '404.css',
                    'navbarFullScreen' => true,
                    'showFooter' => false,
                ], 404);
            }
        });

        $this->renderable(function (QuotaExceededException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'error' => [
                    'code' => 'quota_exceeded',
                    'key' => $e->quotaKey,
                    'limit' => $e->limit,
                    'used' => $e->used,
                ],
            ], 422);
        });
    }
}
