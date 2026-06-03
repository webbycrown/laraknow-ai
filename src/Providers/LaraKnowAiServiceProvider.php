<?php

namespace Webbycrown\LaraknowAi\Providers;

use Webbycrown\LaraknowAi\Console\Commands\AnalyzeProjectCommand;
use Webbycrown\LaraknowAi\Http\Middleware\HandleLaraKnowAiExceptions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

class LaraKnowAiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            $this->configureRateLimiting();

            /*
            |--------------------------------------------------------------------------
            | Routes (Safe Loading)
            |--------------------------------------------------------------------------
            */

            $routePath = __DIR__.'/../Routes/web.php';

            if (file_exists($routePath)) {
                Route::middleware([
                    'web',
                    HandleLaraKnowAiExceptions::class,
                ])->group($routePath);
            } else {
                Log::warning('LaraKnow AI route file is missing; package routes were not registered.', [
                    'path' => $routePath,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Views
            |--------------------------------------------------------------------------
            */

            $viewPath = __DIR__.'/../Resources/views';

            if (is_dir($viewPath)) {
                $this->loadViewsFrom($viewPath, 'laraknow');
            } else {
                Log::warning('LaraKnow AI view directory is missing; package views were not registered.', [
                    'path' => $viewPath,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('LaraKnow AI boot failed; host application boot will continue.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function configureRateLimiting(): void
    {
        try {
            $limiterName = 'laraknow-ai';
            $maxAttempts = 30;
            $decayMinutes = 1;

            RateLimiter::for($limiterName, function (Request $request) use ($maxAttempts, $decayMinutes) {
                $user = $request->user();
                $key = $user && method_exists($user, 'getAuthIdentifier') && $user->getAuthIdentifier() !== null
                    ? 'user:'.$user->getAuthIdentifier()
                    : 'ip:'.$request->ip();

                return Limit::perMinutes($decayMinutes, $maxAttempts)
                    ->by($key)
                    ->response(function () {
                        return response()->json([
                            'message' => 'Too many AI assistant requests. Please try again later.',
                            'reply' => 'Too many AI assistant requests. Please try again later.',
                            'data' => [],
                            'suggestion' => 'Please wait a moment before sending another request.',
                            'error' => [
                                'code' => 429,
                                'type' => 'RateLimited',
                            ],
                        ], 429);
                    });
            });
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI rate limiter registration failed; package boot will continue.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function register(): void
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | Config Merge (Safe)
            |--------------------------------------------------------------------------
            */

            $configPath = __DIR__.'/../config/laraknow.php';

            if (file_exists($configPath)) {
                $this->mergeConfigFrom($configPath, 'laraknow');
            } else {
                Log::warning('LaraKnow AI config file is missing; package defaults were not merged.', [
                    'path' => $configPath,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Publishable Resources
            |--------------------------------------------------------------------------
            */

            if ($this->app->runningInConsole()) {
                $this->commands([
                    AnalyzeProjectCommand::class,
                ]);

                if (file_exists($configPath)) {
                    $this->publishes([
                        $configPath => config_path('laraknow.php'),
                    ], 'laraknow-config');
                }

                $assetPath = __DIR__.'/../Resources/assets';

                if (is_dir($assetPath)) {
                    $this->publishes([
                        $assetPath => public_path('vendor/laraknow'),
                    ], 'laraknow-assets');
                } else {
                    Log::warning('LaraKnow AI asset directory is missing; assets were not registered for publishing.', [
                        'path' => $assetPath,
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error('LaraKnow AI registration failed; host application registration will continue.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
