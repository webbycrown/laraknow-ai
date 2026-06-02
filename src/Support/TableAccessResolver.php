<?php

namespace Webbycrown\LaraknowAi\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class TableAccessResolver
{
    public function __construct(
        private ?AssistantConfig $config = null,
        private ?DatabaseSchemaInspector $schema = null
    ) {
        $this->config ??= new AssistantConfig;
        $this->schema ??= new DatabaseSchemaInspector;
    }

    /**
     * @return array<int, string>
     */
    public function allowedTables(): array
    {
        try {
            return array_values(array_unique($this->existingTables($this->config->configuredTables())));
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI allowed table resolution failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function existingTables(array $tables): array
    {
        return array_values(array_filter($tables, function (string $table) {
            if ($this->schema->hasTable($table)) {
                return true;
            }

            Log::warning('LaraKnow AI configured allowed table does not exist', [
                'table' => $table,
            ]);

            return false;
        }));
    }
}
