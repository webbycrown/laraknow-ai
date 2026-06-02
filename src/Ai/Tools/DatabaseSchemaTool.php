<?php

namespace Webbycrown\LaraknowAi\Ai\Tools;

use Webbycrown\LaraknowAi\Support\AssistantConfig;
use Webbycrown\LaraknowAi\Support\DatabaseSchemaInspector;
use Webbycrown\LaraknowAi\Support\PromptSecurity;
use Webbycrown\LaraknowAi\Support\SchemaContextFilter;
use Webbycrown\LaraknowAi\Support\SensitiveDataPolicy;
use Webbycrown\LaraknowAi\Support\TableAccessResolver;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Tool used by the AI agent to inspect safe database schema details.
 *
 * The schema response respects configured allowed tables and blocked
 * columns before returning table metadata to the assistant.
 */
class DatabaseSchemaTool implements Tool
{
    /**
     * Get the name used by Laravel AI when calling this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'DatabaseSchemaTool';
    }

    /**
     * Get the short description shown to the AI model.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Return allowed tables and safe columns for unknown schema.';
    }

    /**
     * Define the tool input schema.
     *
     * @param  mixed  $schema  The Laravel AI schema builder instance.
     * @return array<string, mixed>
     */
    public function schema($schema): array
    {
        return [
            'include_columns' => $schema->boolean()
                ->required()
                ->description('Return table columns when true.'),
            'tables' => $schema->string()
                ->nullable()
                ->description('Optional comma-separated allowed table names. Provide only the tables relevant to the current request.'),
        ];
    }

    /**
     * Handle the schema lookup request from the AI agent.
     *
     * @param  Request  $request  The validated tool payload.
     * @return string
     */
    public function handle(Request $request): string
    {
        try {
            (new PromptSecurity)->ensureToolAllowed($this->name());

            $allowedTables = (new TableAccessResolver)->allowedTables();
            $requestedTables = trim((string) ($request['tables'] ?? ''));

            if ($requestedTables !== '') {
                $allowedTables = (new SchemaContextFilter)->filterRequestedTables($requestedTables, $allowedTables);
            }

            $blockedColumns = (new AssistantConfig)->blockedColumns();
            $sensitiveData = new SensitiveDataPolicy;
            $schema = new DatabaseSchemaInspector($sensitiveData);

            $database = [];

            foreach ($allowedTables as $table) {

                if (! $schema->hasTable($table)) {

                    Log::warning("AI Assistant: Table [{$table}] not found.");

                    continue;
                }

                if (! (bool) ($request['include_columns'] ?? true)) {
                    $database[$table] = [];
                    continue;
                }

                $columns = $schema->columns($table);

                $columns = array_values(array_filter(
                    $columns,
                    fn ($column) => ! in_array(strtolower((string) $column), $blockedColumns, true)
                        && ! $sensitiveData->isBlockedColumn($table, (string) $column)
                ));

                $database[$table] = $columns;
            }

            Log::info('AI Assistant Schema Loaded', [
                'tables' => array_keys($database),
            ]);

            return json_encode($database, JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            Log::error('DatabaseSchemaTool failed', [
                'message' => $e->getMessage(),
            ]);

            return json_encode([
                'error' => true,
                'message' => 'Unable to read the database schema right now.',
            ]);
        }
    }
}
