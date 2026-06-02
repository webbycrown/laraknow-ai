<?php

namespace Webbycrown\LaraknowAi\Ai\Tools;

use Webbycrown\LaraknowAi\Support\SqlSafetyValidator;
use Webbycrown\LaraknowAi\Support\PromptSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Tool used by the AI agent to execute safe read-only SQL searches.
 *
 * This tool is intended for joins, aggregates, grouping, and analytics
 * that are more flexible than the basic table query tool.
 */
class DatabaseSearchTool implements Tool
{
    /**
     * Get the name used by Laravel AI when calling this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'DatabaseSearchTool';
    }

    /**
     * Get the short description shown to the AI model.
     *
     * @return Stringable|string
     */
    public function description(): Stringable|string
    {
        return 'Run safe read-only SELECT SQL for joins, filters, aggregates, grouping, ordering, and analytics.';
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
            'query' => $schema->string()
                ->required()
                ->description('Safe SELECT SQL'),
        ];
    }

    /**
     * Handle the SQL search request from the AI agent.
     *
     * @param  Request  $request  The validated tool payload.
     * @return string
     */
    public function handle(Request $request): string
    {
        try {
            (new PromptSecurity)->ensureToolAllowed($this->name());

            $query = rtrim(trim((string) $request['query']), " \t\n\r\0\x0B;");

            $validator = new SqlSafetyValidator;
            $query = $validator->validateSelect($query);

            /*
            |--------------------------------------------------------------------------
            | Execute Query
            |--------------------------------------------------------------------------
            */

            $result = $validator->filterBlockedResultColumns(DB::select($query));

            Log::info('AI Assistant Search Query Executed', [
                'query' => $query,
            ]);

            return json_encode(
                $result,
                JSON_PRETTY_PRINT
            );
        } catch (\Throwable $e) {
            Log::error('DatabaseSearchTool failed', [
                'message' => $e->getMessage(),
                'query' => $request['query'] ?? null,
            ]);

            return json_encode([
                'error' => true,
                'message' => SqlSafetyValidator::safeErrorMessage(
                    $e->getMessage(),
                    'Unable to run the database search query. Use DatabaseSchemaTool to verify allowed tables and exact column names, then retry.'
                ),
            ]);
        }
    }
}
