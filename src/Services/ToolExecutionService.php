<?php

namespace Webbycrown\LaraknowAi\Services;

use Webbycrown\LaraknowAi\Models\AgentConversationsMessage;
use Webbycrown\LaraknowAi\Support\ResponseGroundingValidator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ToolExecutionService
 *
 * Responsible for parsing AI tool call results, normalizing data from the
 * agent response, and persisting the final assistant reply. Contains no
 * project-specific logic and is safe to use in any host application.
 */
class ToolExecutionService
{
    /**
     * Extract the final formatter tool payload from an AI response.
     *
     * @param  mixed  $response
     * @return array<string, mixed>|null
     */
    public function finalFormatterData($response): ?array
    {
        foreach ($response->toolCalls->reverse() as $toolCall) {
            if (in_array($toolCall->name, ['response', 'json'], true) && is_array($toolCall->arguments)) {
                return $toolCall->arguments;
            }
        }

        $formatterText = trim($this->finalFormatterToolResult($response));

        if ($formatterText !== '') {
            return $this->decodeJsonPayload($formatterText);
        }

        return null;
    }

    /**
     * Get the final formatter tool result as raw text.
     *
     * @param  mixed  $response
     * @return string
     */
    public function finalFormatterToolResult($response): string
    {
        foreach ($response->toolResults->reverse() as $toolResult) {
            if (in_array($toolResult->name, ['response', 'json'], true)) {
                return (string) $toolResult->result;
            }
        }

        return '';
    }

    /**
     * Get the most recent database tool result from an AI response.
     *
     * @param  mixed  $response
     * @return array<int|string, mixed>
     */
    public function latestDatabaseToolData($response): array
    {
        foreach ($response->toolResults->reverse() as $toolResult) {
            if (! in_array($toolResult->name, ['DatabaseIntentQueryTool', 'DatabaseQueryTool', 'DatabaseSearchTool', 'DatabaseReportTool'], true)) {
                continue;
            }

            $decoded = json_decode((string) $toolResult->result, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Get all successful database tool data from an AI response.
     *
     * When a model answers a multi-part metric prompt with several safe tool
     * calls, using only the latest result can collapse a complete report into a
     * single value. This keeps the behavior host-neutral by merging only
     * verified tool rows/sections and leaving error handling to the existing
     * latest-result guard.
     *
     * @param  mixed  $response
     * @return array<int|string, mixed>
     */
    public function databaseToolData($response): array
    {
        $rows = [];
        $sections = [];

        foreach ($response->toolResults as $toolResult) {
            if (! in_array($toolResult->name, ['DatabaseIntentQueryTool', 'DatabaseQueryTool', 'DatabaseSearchTool', 'DatabaseReportTool'], true)) {
                continue;
            }

            $decoded = json_decode((string) $toolResult->result, true);

            if (! is_array($decoded) || ($decoded['error'] ?? false) === true) {
                continue;
            }

            if (isset($decoded['sections']) && is_array($decoded['sections'])) {
                $sections = array_merge($sections, array_values(array_filter(
                    $decoded['sections'],
                    fn ($section): bool => is_array($section)
                )));

                continue;
            }

            if (array_is_list($decoded)) {
                $rows = array_merge($rows, array_values(array_filter(
                    $decoded,
                    fn ($row): bool => is_array($row)
                )));
            }
        }

        if (! empty($sections)) {
            return ['sections' => $sections];
        }

        if (! empty($rows)) {
            return $rows;
        }

        return $this->latestDatabaseToolData($response);
    }

    /**
     * Check whether any database tool was called in the AI response.
     *
     * @param  mixed  $response
     * @return bool
     */
    public function hasDatabaseToolResult($response): bool
    {
        foreach ($response->toolResults->reverse() as $toolResult) {
            if (in_array($toolResult->name, ['DatabaseIntentQueryTool', 'DatabaseQueryTool', 'DatabaseSearchTool', 'DatabaseReportTool'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prevent failed database tool calls from being presented as empty data.
     *
     * @param  array<string, mixed>  $payload
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    public function guardAgainstDatabaseToolErrorAnswer(array $payload, $response): array
    {
        $latestDatabaseResult = $this->latestDatabaseToolData($response);

        if (($latestDatabaseResult['error'] ?? false) !== true) {
            return $payload;
        }

        return [
            ...$payload,
            'reply'      => 'I could not verify that data right now.',
            'data'       => [],
            'suggestion' => 'Please try again with a more specific question.',
        ];
    }

    /**
     * Keep database-backed replies grounded in verified tool output.
     *
     * @param  array<string, mixed>  $payload
     * @param  mixed  $response
     * @param  string|null  $prompt
     * @return array<string, mixed>
     */
    public function groundDatabaseBackedResponse(array $payload, $response, ?string $prompt = null): array
    {
        return (new ResponseGroundingValidator)->validate(
            $payload,
            $this->hasDatabaseToolResult($response),
            $prompt
        );
    }

    /**
     * Keep the stored conversation message identical to the API response.
     *
     * @param  mixed  $response
     * @param  array<string, mixed>  $payload
     * @return void
     */
    public function persistNormalizedAssistantReply($response, array $payload): void
    {
        try {
            $conversationId = $payload['conversation_id'] ?? $response->conversationId ?? null;
            $reply = trim((string) ($payload['reply'] ?? ''));

            if (! $conversationId || $reply === '') {
                return;
            }

            $message = AgentConversationsMessage::query()
                ->where('conversation_id', $conversationId)
                ->where('role', 'assistant')
                ->latest('created_at')
                ->first();

            if (! $message) {
                return;
            }

            $message->forceFill(['content' => $reply])->save();
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI normalized assistant reply persistence failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decode the first JSON object found in a text response.
     *
     * @param  string  $text
     * @return array<string, mixed>|null
     */
    public function decodeJsonPayload(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
