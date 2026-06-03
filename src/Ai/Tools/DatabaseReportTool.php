<?php

namespace Webbycrown\LaraknowAi\Ai\Tools;

use Webbycrown\LaraknowAi\Support\SqlSafetyValidator;
use Webbycrown\LaraknowAi\Support\PromptSecurity;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Tool used by the AI agent to run multiple safe read-only report queries.
 *
 * This is intended for dashboard-style prompts that need several independent
 * aggregates or small result sets in a single assistant turn.
 */
class DatabaseReportTool implements Tool
{
    /**
     * Get the name used by Laravel AI when calling this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return 'DatabaseReportTool';
    }

    /**
     * Get the short description shown to the AI model.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Run multiple safe read-only SELECT queries for reports and multi-part analytics.';
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
            'queries' => $schema->string()
                ->required()
                ->description('Single-line JSON array of {label,query}; each query is safe one-line SELECT SQL.'),
        ];
    }

    /**
     * Handle the report query request from the AI agent.
     *
     * @param  Request  $request  The validated tool payload.
     * @return string
     */
    public function handle(Request $request): string
    {
        try {
            (new PromptSecurity)->ensureToolAllowed($this->name());

            $queries = $this->decodeQueries((string) $request['queries']);

            if (empty($queries)) {
                throw new Exception('No report queries were provided.');
            }

            $configuredMaxQueries = 3;
            $maxQueriesCap = 3;
            $maxQueries = min($configuredMaxQueries, $maxQueriesCap);
            $sections = [];
            $validator = new SqlSafetyValidator;

            foreach (array_slice($queries, 0, max(1, $maxQueries)) as $queryConfig) {
                $label = trim((string) ($queryConfig['label'] ?? 'Report section'));
                $query = $validator->validateSelect((string) ($queryConfig['query'] ?? ''));

                $sections[] = [
                    'label' => $label !== '' ? $label : 'Report section',
                    'query' => $query,
                    'data' => $validator->filterBlockedResultColumns(DB::select($query)),
                ];
            }

            Log::info('AI Assistant Report Queries Executed', [
                'sections' => array_column($sections, 'label'),
            ]);

            return json_encode([
                'sections' => $sections,
            ], JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            Log::error('DatabaseReportTool failed', [
                'message' => $e->getMessage(),
                'queries' => $request['queries'] ?? null,
            ]);

            return json_encode([
                'error' => true,
                'message' => SqlSafetyValidator::safeErrorMessage(
                    $e->getMessage(),
                    'Unable to run the database report. Use DatabaseSchemaTool to verify allowed tables and exact column names, then retry.'
                ),
            ]);
        }
    }

    /**
     * Decode report query definitions.
     *
     * @param  string  $payload
     * @return array<int, array<string, mixed>>
     */
    private function decodeQueries(string $payload): array
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            $decoded = json_decode($this->escapeNewlinesInsideJsonStrings($payload), true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['query']) && is_scalar($decoded['query'])) {
            $decoded = [$decoded];
        } elseif (isset($decoded['queries']) && is_array($decoded['queries'])) {
            $decoded = $decoded['queries'];
        }

        return array_values(array_filter(
            $decoded,
            fn ($item) => is_array($item) && isset($item['query'])
        ));
    }

    /**
     * Make provider-generated JSON strings parseable when raw newlines appear
     * inside quoted SQL strings.
     *
     * @param  string  $payload
     * @return string
     */
    private function escapeNewlinesInsideJsonStrings(string $payload): string
    {
        $result = '';
        $inString = false;
        $escaped = false;

        foreach (str_split($payload) as $character) {
            if ($escaped) {
                $result .= $character;
                $escaped = false;
                continue;
            }

            if ($character === '\\') {
                $result .= $character;
                $escaped = true;
                continue;
            }

            if ($character === '"') {
                $inString = ! $inString;
                $result .= $character;
                continue;
            }

            if ($inString && ($character === "\n" || $character === "\r")) {
                $result .= ' ';
                continue;
            }

            $result .= $character;
        }

        return $result;
    }

}
