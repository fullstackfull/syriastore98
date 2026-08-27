<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use App\Exceptions;
use Illuminate\Session\TokenMismatchException;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function render($request, Throwable $exception)
    {
        $response = parent::render($request, $exception);

        if ($exception instanceof MethodNotAllowedHttpException && $request->is('api/*')) {
            // Handle MethodNotAllowedHttpException for API routes
            return response()->json([ 'status'    => 0 , 'message' => __('Method not allowed for API')], 405);
        }

        if ($exception instanceof TokenMismatchException) {
            // Check if the request is for the frontend or backend
            if ($request->is('{storeSlug}/?')) {
                return redirect()->route('customer.login', ['storeSlug' => $request->route('storeSlug')]);
            }
            return redirect()->route('login');
        }

        return parent::render($request, $exception);
    }

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
