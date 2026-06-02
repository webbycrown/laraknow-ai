<?php

namespace Webbycrown\LaraknowAi\Ai\Tools;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Tool used to format the assistant's final response payload.
 *
 * The tool keeps the reply, data, and suggestion fields predictable for
 * the front-end chat interface.
 */
class ResponseTool implements Tool
{
    /**
     * Create a new response formatting tool instance.
     *
     * @param  string  $name  The tool name exposed to Laravel AI.
     * @return void
     */
    public function __construct(private readonly string $name = 'response') {}

    /**
     * Get the name used by Laravel AI when calling this tool.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the short description shown to the AI model.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Generate final response.';
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

            'reply' => $schema->string()
                ->required()
                ->description('Short user-facing reply.'),

            'data' => $schema->string()
                ->required()
                ->description('JSON string of safe rows, or [].'),

            'suggestion' => $schema->string()->required()->nullable(),
        ];
    }

    /**
     * Handle the final response formatting request.
     *
     * @param  Request  $request  The validated tool payload.
     * @return string
     */
    public function handle(Request $request): string
    {
        try {
            return json_encode([

                'reply' => $request['reply'] ?? '',

                'data' => $this->decodeData($request['data'] ?? '[]'),

                'suggestion' => $request['suggestion'] ?? null,

            ]);
        } catch (\Throwable $e) {
            Log::error('ResponseTool failed', [
                'message' => $e->getMessage(),
            ]);

            return json_encode([
                'reply' => 'Sorry, I could not format the response right now.',
                'data' => [],
                'suggestion' => null,
            ]);
        }
    }

    /**
     * Decode JSON data passed by the model into a PHP value.
     *
     * @param  mixed  $data
     * @return mixed
     */
    private function decodeData(mixed $data): mixed
    {
        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode((string) $data, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }
}
