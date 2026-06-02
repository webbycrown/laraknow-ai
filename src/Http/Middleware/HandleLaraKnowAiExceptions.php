<?php

namespace Webbycrown\LaraknowAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class HandleLaraKnowAiExceptions
{
    /**
     * Handle package route errors without breaking the host application.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $requestId = (string) Str::uuid();
            $message = $status === 419
                ? 'Your chat session expired. Please refresh the page and try again.'
                : 'Something went wrong. Please try again later.';

            Log::error('LaraKnow AI package route failed', [
                'request_id' => $requestId,
                'status' => $status,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => optional(Auth::user())->id,
                'route' => optional($request->route())->getName(),
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'trace' => $e->getTraceAsString(),
            ]);

            $payload = [
                'message' => $message,
                'reply' => $message,
                'data' => [],
                'suggestion' => 'Please try again in a moment, or contact support if the issue persists.',
                'request_id' => $requestId,
                'error' => [
                    'code' => $status,
                    'type' => class_basename($e),
                    'request_id' => $requestId,
                ],
            ];

            if (config('app.debug')) {
                $payload['error']['detail'] = $e->getMessage();
                $payload['error']['file'] = $e->getFile();
                $payload['error']['line'] = $e->getLine();
            }

            return response()
                ->json($payload, $status)
                ->header('X-LaraKnow-Request-Id', $requestId);
        }
    }
}
