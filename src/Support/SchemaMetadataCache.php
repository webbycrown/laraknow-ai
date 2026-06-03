<?php

namespace Webbycrown\LaraknowAi\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SchemaMetadataCache
{
    /**
     * @var array<string, mixed>
     */
    private static array $memory = [];

    private static bool $cacheUnavailable = false;

    public function remember(string $key, callable $callback): mixed
    {
        $key = $this->key($key);

        if (array_key_exists($key, self::$memory)) {
            return self::$memory[$key];
        }

        if (self::$cacheUnavailable || $this->usesDatabaseCacheStore()) {
            return self::$memory[$key] = $callback();
        }

        try {
            $value = Cache::remember($key, $this->ttl(), $callback);

            return self::$memory[$key] = $value;
        } catch (Throwable $e) {
            self::$cacheUnavailable = true;

            Log::warning('LaraKnow AI schema cache unavailable; using request memory cache.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return self::$memory[$key] = $callback();
        }
    }

    private function key(string $key): string
    {
        return 'laraknow:schema:'.sha1(implode('|', [
            (string) config('database.default'),
            (string) DB::connection()->getDatabaseName(),
            $key,
            md5(json_encode(config('laraknow.allowed_tables', []))),
            md5(json_encode(config('laraknow.blocked_columns', []))),
        ]));
    }

    private function ttl(): int
    {
        return 600;
    }

    private function usesDatabaseCacheStore(): bool
    {
        $store = (string) config('cache.default');

        return (string) config("cache.stores.{$store}.driver") === 'database';
    }
}
