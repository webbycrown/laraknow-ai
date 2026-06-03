<?php

namespace Webbycrown\LaraknowAi\Ai\Tools;

use Webbycrown\LaraknowAi\Support\AssistantConfig;
use Webbycrown\LaraknowAi\Support\DatabaseSchemaInspector;
use Webbycrown\LaraknowAi\Support\PromptSecurity;
use Webbycrown\LaraknowAi\Support\SensitiveDataPolicy;
use Webbycrown\LaraknowAi\Support\TableAccessResolver;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Tool used by the AI agent to read records from an allowed table.
 *
 * This tool accepts simple table, column, where, and limit parameters,
 * then returns a JSON encoded database result for the assistant.
 */
class DatabaseQueryTool implements Tool
{
    /**
     * Get the name used by Laravel AI when calling this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'DatabaseQueryTool';
    }

    /**
     * Get the short description shown to the AI model.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Read records from one allowed table. Use DatabaseSearchTool for joins, expressions, aggregates, or table.column fields.';
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

            'table' => $schema->string()->required(),

            'columns' => $schema->string()
                ->required()
                ->description('Comma-separated exact column names, or * for safe columns'),

            'where_column' => $schema->string()->required()->nullable(),

            'where_value' => $schema->string()->required()->nullable(),

            'limit' => $schema->integer()->required()->nullable(),
        ];
    }

    /**
     * Handle the database query request from the AI agent.
     *
     * @param  Request  $request  The validated tool payload.
     * @return string
     */
    public function handle(Request $request): string
    {
        try {
            (new PromptSecurity)->ensureToolAllowed($this->name());

            $table = (string) $request['table'];

            $allowedTables = (new TableAccessResolver)->allowedTables();

            if (empty($allowedTables)) {
                throw new Exception('No allowed tables are configured.');
            }

            if (
                ! in_array($table, $allowedTables, true)
            ) {
                throw new Exception('Table not allowed.');
            }

            $blockedColumns = (new AssistantConfig)->blockedColumns();
            $sensitiveData = new SensitiveDataPolicy;
            $schema = new DatabaseSchemaInspector($sensitiveData);

            if (! $schema->hasTable($table)) {
                throw new Exception("Table [{$table}] does not exist.");
            }

            $safeColumns = $this->safeColumns($table, $blockedColumns, $sensitiveData, $schema);

            $columns = explode(
                ',',
                (string) $request['columns']
            );

            $columns = array_map('trim', $columns);

            if (in_array('*', $columns, true)) {
                $columns = $safeColumns;
            } else {
                $columns = $this->validatedColumns($columns, $safeColumns);
            }

            if (empty($columns)) {
                throw new Exception('No safe columns were requested.');
            }

            $limit = min(
                (int) ($request['limit'] ?? 10),
                50
            );

            $query = DB::table($table)
                ->select($columns);

            $query = \Webbycrown\LaraknowAi\Laraknow::applyQueryScope($query, $table);

            if (
                ! empty($request['where_column'])
                && ! empty($request['where_value'])
            ) {
                if (str_contains((string) $request['where_column'], '.')) {
                    throw new Exception('DatabaseQueryTool supports same-table filter columns only. Use DatabaseSearchTool for joins or qualified columns.');
                }

                if (in_array(strtolower((string) $request['where_column']), $blockedColumns, true)) {
                    throw new Exception('Blocked filter column requested.');
                }

                if ($sensitiveData->isBlockedColumn($table, (string) $request['where_column'])) {
                    throw new Exception('Blocked filter column requested.');
                }

                if (! in_array((string) $request['where_column'], $safeColumns, true)) {
                    throw new Exception("Unknown column [{$request['where_column']}] on table [{$table}]. Available safe columns: ".implode(', ', $safeColumns));
                }

                $query->where(
                    $request['where_column'],
                    $request['where_value']
                );
            }

            $result = $this->filterBlockedColumns($query->limit($limit)->get(), $blockedColumns, $table);

            return json_encode($result, JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            Log::error('DatabaseQueryTool failed', [
                'message' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return json_encode([
                'error' => true,
                'message' => $this->safeErrorMessage($e->getMessage()),
            ]);
        }
    }

    /**
     * Resolve exact safe columns for a table.
     *
     * @param  array<int, string>  $blockedColumns
     * @return array<int, string>
     */
    private function safeColumns(string $table, array $blockedColumns, SensitiveDataPolicy $sensitiveData, DatabaseSchemaInspector $schema): array
    {
        return array_values(array_filter(
            $schema->columns($table),
            fn ($column) => ! in_array(strtolower((string) $column), $blockedColumns, true)
                && ! $sensitiveData->isBlockedColumn($table, (string) $column)
        ));
    }

    /**
     * Keep only exact schema-backed safe columns.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $safeColumns
     * @return array<int, string>
     *
     * @throws Exception
     */
    private function validatedColumns(array $columns, array $safeColumns): array
    {
        $columns = array_values(array_filter(array_unique($columns), fn ($column) => $column !== ''));
        $invalidColumns = array_values(array_diff($columns, $safeColumns));

        if (! empty($invalidColumns)) {
            throw new Exception('Unknown or unsafe columns requested: '.implode(', ', $invalidColumns).'. Available safe columns: '.implode(', ', $safeColumns));
        }

        return $columns;
    }

    /**
     * Return a model-useful error without exposing stack traces or SQL internals.
     */
    private function safeErrorMessage(string $message): string
    {
        if (preg_match('/\b(count|sum|avg|average|min|max)\s*\(/i', $message)) {
            return 'DatabaseQueryTool does not support aggregate expressions. Use DatabaseSearchTool or DatabaseReportTool with safe SELECT aggregate SQL for count, total, sum, average, minimum, or maximum questions.';
        }

        if (
            str_contains($message, 'Available safe columns:')
            || str_starts_with($message, 'DatabaseQueryTool supports same-table filter columns only.')
        ) {
            return $message;
        }

        return 'Unable to run the database query. Use DatabaseSchemaTool to verify the allowed table and exact safe column names, then retry. If no rows are returned, explain that no records are available.';
    }

    /**
     * Remove blocked columns from query results, including SELECT * results.
     *
     * @param  iterable<int, mixed>  $rows
     * @param  array<int, string>  $blockedColumns
     * @return array<int, array<string, mixed>>
     */
    private function filterBlockedColumns(iterable $rows, array $blockedColumns, string $table): array
    {
        $sensitiveData = new SensitiveDataPolicy;

        return collect($rows)
            ->map(function ($row) use ($blockedColumns, $sensitiveData, $table) {
                $values = (array) $row;

                foreach (array_keys($values) as $column) {
                    if (
                        in_array(strtolower((string) $column), $blockedColumns, true)
                        || $sensitiveData->isBlockedColumn($table, (string) $column)
                    ) {
                        unset($values[$column]);
                    }
                }

                return $values;
            })
            ->all();
    }
}
