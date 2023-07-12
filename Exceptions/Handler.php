<?php

namespace App\Exceptions;

use App\Traits\ApiResponser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    use ApiResponser;

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
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
    }

    public function render($request, Throwable $exception)
    {
        if ($exception instanceof ThrottleRequestsException && $request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => trans('errors.error'),
                'errors'  => [['msg' => trans('passwords.throttled')]],
                'data'    => [],
            ], $exception->getStatusCode(), $exception->getHeaders());
        }

        if ($exception instanceof QueryException && $request->wantsJson()) {
            $bindings = $exception->getBindings();
            if ($exception->getCode() == 23505 && stristr($exception->getMessage(), 'products_unique_not_deleted')) {
                return response()->json([
                    'success' => false,
                    'message' => trans('errors.error'),
                    'errors'  => [
                        'msg' => trans(
                            'api_errors.product_not_unique',
                            ['title' => $bindings[0], 'sku' => $bindings[1]]
                        )
                    ],
                    'data'    => [],
                ], 403);
            }

            logger()->critical($exception);

            return response()->json([
                'success' => false,
                'message' => trans('errors.error'),
                'errors'  => ['msg' => trans('api_errors.system_error')],
                'data'    => [],
            ], 403);
        }

        if ($exception instanceof ModelNotFoundException && $request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => trans('errors.error'),
                'errors'  => ['msg' => trans('errors.forbidden')],
                'data'    => [],
            ], 403);
        }

        if($exception instanceof NotFoundHttpException || $exception instanceof MethodNotAllowedHttpException) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => trans('errors.error'),
                    'errors'  => ['msg' => trans('errors.page_not_found')],
                    'data'    => [],
                ], 404);
            }
        }

        return parent::render($request, $exception);
    }

    /**
     * Convert a validation exception into a JSON response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Validation\ValidationException $exception
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        return response()->json([
            'message' => __('errors.error'),
            'errors'  => $exception->errors(),
        ], $exception->status);
    }
}
