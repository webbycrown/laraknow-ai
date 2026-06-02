<?php

namespace Webbycrown\LaraknowAi\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseSchemaInspector
{
    public function __construct(
        private ?SensitiveDataPolicy $sensitiveData = null,
        private ?SchemaMetadataCache $cache = null
    ) {
        $this->sensitiveData ??= new SensitiveDataPolicy;
        $this->cache ??= new SchemaMetadataCache;
    }

    /**
     * @return array<int, string>
     */
    public function tables(): array
    {
        try {
            return $this->cache->remember('tables', function () {
                $builder = DB::connection()->getSchemaBuilder();

                if (method_exists($builder, 'getTableListing')) {
                    return $this->onlyCurrentDatabaseTables($builder->getTableListing());
                }

                return $this->onlyCurrentDatabaseTables(array_map(
                    fn ($table) => (string) array_values((array) $table)[0],
                    DB::select('SHOW TABLES')
                ));
            });
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI database table inspection failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Keep auto-detection inside the active database/schema only.
     *
     * Some MySQL connections can list schema-qualified tables from every
     * database visible to the user. Auto-detect must never cross that boundary.
     *
     * @param  array<int, mixed>  $tables
     * @return array<int, string>
     */
    private function onlyCurrentDatabaseTables(array $tables): array
    {
        $database = strtolower((string) DB::connection()->getDatabaseName());
        $safeTables = [];

        foreach ($tables as $table) {
            $table = trim((string) $table);

            if ($table === '') {
                continue;
            }

            if (str_contains($table, '.')) {
                [$schema, $name] = array_pad(explode('.', $table, 2), 2, '');

                if (strtolower($schema) !== $database || $name === '') {
                    continue;
                }

                $table = $name;
            }

            $safeTables[] = $table;
        }

        return array_values(array_unique($safeTables));
    }

    public function hasTable(string $table): bool
    {
        try {
            return (bool) $this->cache->remember('has-table:'.$table, fn () => Schema::hasTable($table));
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI table existence check failed', [
                'table' => $table,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    public function columns(string $table): array
    {
        try {
            if (! $this->hasTable($table)) {
                return [];
            }

            return $this->cache->remember('columns:'.$table, fn () => Schema::getColumnListing($table));
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI column inspection failed', [
                'table' => $table,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    public function safeColumns(string $table, array $blockedColumns): array
    {
        try {
            if (! $this->hasTable($table)) {
                return [];
            }

            return array_values(array_filter(
                $this->columns($table),
                fn ($column) => ! in_array(strtolower((string) $column), $blockedColumns, true)
                    && ! $this->sensitiveData->isBlockedColumn($table, (string) $column)
            ));
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI safe column inspection failed', [
                'table' => $table,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
