<?php

use Webbycrown\LaraknowAi\Http\Controllers\AiAssistantController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Prefixed Package Routes
|--------------------------------------------------------------------------
|
| These namespaced routes are the recommended package endpoints. The prefix
| may be customized from the laraknow configuration file.
|
*/

$prefix = config('laraknow.route_prefix', 'laraknow-ai');
$routeNamePrefix = trim((string) config('laraknow.route_name_prefix', 'laraknow-ai')) ?: 'laraknow-ai';
$middleware = array_values(array_filter((array) config('laraknow.route_middleware', [])));
$rateLimiterName = trim((string) config('laraknow.rate_limiter_name', 'laraknow-ai'));

if ($rateLimiterName !== '') {
    $middleware[] = 'throttle:'.$rateLimiterName;
}

$middleware = array_values(array_unique($middleware));

Route::prefix($prefix)->middleware($middleware)->group(function () use ($routeNamePrefix) {

    Route::post('/chat', [AiAssistantController::class, 'chat'])
        ->name($routeNamePrefix.'.chat');

    Route::get('/conversations', [AiAssistantController::class, 'conversations'])
        ->name($routeNamePrefix.'.conversations');

    Route::post('/conversations', [AiAssistantController::class, 'createConversation'])
        ->name($routeNamePrefix.'.conversations.create');

    Route::get('/conversations/{conversation}/messages', [AiAssistantController::class, 'conversationMessages'])
        ->name($routeNamePrefix.'.conversations.messages');

    Route::delete('/conversations/{conversation}', [AiAssistantController::class, 'deleteConversation'])
        ->name($routeNamePrefix.'.conversations.delete');

    Route::get('/', function () {
        try {
            if (! view()->exists('laraknow::laraknow-ai.welcome')) {
                return response()->json([
                    'message' => 'LaraKnow AI package view is unavailable.',
                    'reply' => 'LaraKnow AI package view is unavailable.',
                    'data' => [],
                    'error' => [
                        'code' => 503,
                        'type' => 'PackageViewUnavailable',
                    ],
                ], 503);
            }

            return view('laraknow::laraknow-ai.welcome');
        } catch (\Throwable $e) {
            Log::error('LaraKnow AI welcome view failed before rendering.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'LaraKnow AI package route failed.',
                'reply' => 'LaraKnow AI package route failed.',
                'data' => [],
                'error' => [
                    'code' => 500,
                    'type' => class_basename($e),
                ],
            ], 500);
        }
    })->name($routeNamePrefix.'.welcome');
});
