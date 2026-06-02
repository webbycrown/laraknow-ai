<?php

namespace Webbycrown\LaraknowAi\Ai\Tools;

use Webbycrown\LaraknowAi\Support\PromptSecurity;
use Webbycrown\LaraknowAi\Support\StructuredQueryIntentExecutor;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DatabaseIntentQueryTool implements Tool
{
    public function name(): string
    {
        return 'DatabaseIntentQueryTool';
    }

    public function description(): string
    {
        return 'Read records from one allowed table using structured JSON query intent. Prefer this over raw SQL for simple filters, ordering, and limits.';
    }

    /**
     * @param  mixed  $schema
     * @return array<string, mixed>
     */
    public function schema($schema): array
    {
        return [
            'intent' => $schema->string()
                ->required()
                ->description('JSON object: {"table":"...","columns":["..."],"filters":[{"column":"...","operator":"=","value":"..."}],"order_by":[{"column":"...","direction":"asc"}],"limit":10}. Same-table reads only.'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            (new PromptSecurity)->ensureToolAllowed($this->name());

            $intent = json_decode((string) $request['intent'], true);

            if (! is_array($intent)) {
                throw new \Exception('Invalid structured query intent JSON.');
            }

            $result = (new StructuredQueryIntentExecutor)->execute($intent);

            return json_encode($result, JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            Log::error('DatabaseIntentQueryTool failed', [
                'message' => $e->getMessage(),
                'intent' => $request['intent'] ?? null,
            ]);

            return json_encode([
                'error' => true,
                'message' => $this->safeErrorMessage($e->getMessage()),
            ]);
        }
    }

    private function safeErrorMessage(string $message): string
    {
        foreach ([
            'A table is required.',
            'No allowed tables are configured.',
            'Table [',
            'Unknown or unsafe columns requested:',
            'Unknown column [',
            'Structured query intents support same-table columns only.',
            'Blocked filter column requested.',
            'Unsupported filter operator',
            'Unsupported order direction',
            'At least one column is required.',
            'Invalid structured query intent JSON.',
            'This tool is not enabled for this assistant.',
        ] as $safePrefix) {
            if (str_starts_with($message, $safePrefix)) {
                return $message;
            }
        }

        return 'Unable to run the structured database query. Use DatabaseSchemaTool to verify allowed tables and exact safe column names, then retry.';
    }
}
