<?php

namespace Webbycrown\LaraknowAi\Http\Controllers;

use Webbycrown\LaraknowAi\Models\AgentConversations;
use Webbycrown\LaraknowAi\Models\AgentConversationsMessage;
use Webbycrown\LaraknowAi\Services\AgentCoordinator;
use Webbycrown\LaraknowAi\Services\ChatService;
use Webbycrown\LaraknowAi\Services\ConversationService;
use Webbycrown\LaraknowAi\Services\ResponseNormalizerService;
use Webbycrown\LaraknowAi\Services\ToolExecutionService;
use Webbycrown\LaraknowAi\Support\AuditLogger;
use Webbycrown\LaraknowAi\Support\PromptSecurity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Controller responsible for handling AI assistant chat requests.
 *
 * This controller validates prompts, manages conversation IDs, delegates
 * the AI agent call to ChatService, and returns normalised JSON responses
 * for the chat front-end.
 *
 * All heavy lifting (agent execution, tool result parsing, response
 * normalisation, conversation lifecycle) has been extracted into dedicated
 * service classes so this controller stays thin and maintainable.
 */
class AiAssistantController
{
    private ConversationService $conversationService;
    private ChatService $chatService;

    public function __construct()
    {
        $this->conversationService = new ConversationService;

        $this->chatService = new ChatService(
            new AgentCoordinator,
            $this->conversationService,
            new ResponseNormalizerService,
            new ToolExecutionService
        );
    }

    // -------------------------------------------------------------------------
    // Public endpoints
    // -------------------------------------------------------------------------

    /**
     * Placeholder endpoint for future authenticated user details.
     */
    public function user() {}

    /**
     * Return all conversations owned by the logged-in user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversations()
    {
        return $this->safely('conversations.index', request(), function () {
            if ($response = $this->conversationStorageUnavailableResponse('conversations.index', request())) {
                return $response;
            }

            $user = Auth::user();

            if (! $user) {
                return response()->json(['conversations' => []], 401);
            }

            $this->conversationService->repairMissingConversationsForUser($user->id);

            $conversations = AgentConversations::query()
                ->where('user_id', $user->id)
                ->withCount('messages')
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get()
                ->map(fn (AgentConversations $c) => $this->conversationService->conversationPayload($c))
                ->values();

            return response()->json(['conversations' => $conversations]);
        });
    }

    /**
     * Create a blank conversation for the logged-in user.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createConversation(Request $request)
    {
        return $this->safely('conversations.create', $request, function () use ($request) {
            if ($response = $this->conversationStorageUnavailableResponse('conversations.create', $request)) {
                return $response;
            }

            $request->validate([
                'title' => ['nullable', 'string', 'max:100'],
            ]);

            $user = Auth::user();

            if (! $user) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $conversation = $this->conversationService->createConversationRecord(
                $user->id,
                $request->input('title') ?: 'New conversation'
            );

            $this->conversationService->rememberConversationId($request, $conversation->id);

            return response()->json([
                'conversation'    => $this->conversationService->conversationPayload($conversation),
                'conversation_id' => $conversation->id,
            ], 201);
        });
    }

    /**
     * Return messages for a single logged-in user's conversation.
     *
     * @param  string  $conversationId
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversationMessages(string $conversationId)
    {
        return $this->safely('conversations.messages', request(), function () use ($conversationId) {
            if ($response = $this->conversationStorageUnavailableResponse('conversations.messages', request())) {
                return $response;
            }

            $user = Auth::user();

            if (! $user) {
                return response()->json(['messages' => []], 401);
            }

            $this->conversationService->repairMissingConversationsForUser($user->id);

            $conversation = AgentConversations::query()
                ->where('user_id', $user->id)
                ->whereKey($conversationId)
                ->firstOrFail();

            $promptSecurity = new PromptSecurity;

            $messages = AgentConversationsMessage::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('role', ['user', 'assistant'])
                ->orderBy('created_at')
                ->get()
                ->map(fn (AgentConversationsMessage $message) => [
                    'id'         => $message->id,
                    'role'       => $message->role === 'user' ? 'user' : 'bot',
                    'content'    => $this->messageContent($message->content, $promptSecurity),
                    'created_at' => optional($message->created_at)->toIso8601String(),
                    'time'       => optional($message->created_at)->format('H:i'),
                ])
                ->filter(fn (array $message) => $message['content'] !== '')
                ->values();

            return response()->json([
                'conversation' => [
                    'id'    => $conversation->id,
                    'title' => $conversation->title ?: 'Untitled chat',
                ],
                'messages' => $messages,
            ]);
        });
    }

    /**
     * Delete a logged-in user's saved conversation and its stored messages.
     *
     * @param  string  $conversationId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteConversation(string $conversationId)
    {
        return $this->safely('conversations.delete', request(), function () use ($conversationId) {
            if ($response = $this->conversationStorageUnavailableResponse('conversations.delete', request())) {
                return $response;
            }

            $user = Auth::user();

            if (! $user) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $conversation = AgentConversations::query()
                ->where('user_id', $user->id)
                ->whereKey($conversationId)
                ->firstOrFail();

            DB::transaction(function () use ($conversation, $user) {
                AgentConversationsMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $user->id)
                    ->delete();

                $conversation->delete();
            });

            return response()->json(['message' => 'Conversation deleted.']);
        });
    }

    /**
     * Handle an incoming chat prompt.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function chat(Request $request)
    {
        return $this->safely('chat', $request, function () use ($request) {
            if ($response = $this->conversationStorageUnavailableResponse('chat', $request)) {
                return $response;
            }

            $request->validate([
                'prompt' => ['required', 'string', 'min:1'],
                'conversation_id' => ['nullable', 'string'],
                'reset_conversation' => ['nullable', 'boolean'],
            ]);

            return $this->chatService->handle($request);
        });
    }

    // -------------------------------------------------------------------------
    // Exception handling
    // -------------------------------------------------------------------------

    /**
     * Convert package exceptions into JSON so host applications keep running.
     *
     * @param  string  $endpoint
     * @param  Request|null  $request
     * @param  callable  $callback
     * @return \Illuminate\Http\JsonResponse
     */
    private function safely(string $endpoint, ?Request $request, callable $callback)
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            return $this->logAndRespond($endpoint, $request, $e, 422, 'The request data is invalid.', [
                'errors' => $e->errors(),
            ]);
        } catch (RateLimitedException $e) {
            return $this->logAndRespond($endpoint, $request, $e, 429, $this->rateLimitMessage(), [
                'suggestion' => $this->rateLimitMessage(),
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->logAndRespond($endpoint, $request, $e, 404, 'The requested conversation was not found.');
        } catch (QueryException $e) {
            return $this->logAndRespond($endpoint, $request, $e, 503, 'The assistant storage is not ready. Please run the required migrations.');
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 429) {
                return $this->logAndRespond($endpoint, $request, $e, 429, $this->rateLimitMessage(), [
                    'suggestion' => $this->rateLimitMessage(),
                ]);
            }

            return $this->logAndRespond($endpoint, $request, $e, $e->getStatusCode(), $e->getMessage() ?: 'Something went wrong. Please try again later.');
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'rate limit')) {
                return $this->logAndRespond($endpoint, $request, $e, 429, $this->rateLimitMessage(), [
                    'suggestion' => $this->rateLimitMessage(),
                ]);
            }

            return $this->logAndRespond($endpoint, $request, $e, 500, 'Something went wrong. Please try again later.');
        }
    }

    /**
     * Log a developer-friendly package error and return a stable JSON payload.
     *
     * @param  string  $endpoint
     * @param  Request|null  $request
     * @param  Throwable  $e
     * @param  int  $status
     * @param  string  $message
     * @param  array<string, mixed>  $extra
     * @return \Illuminate\Http\JsonResponse
     */
    private function logAndRespond(string $endpoint, ?Request $request, Throwable $e, int $status, string $message, array $extra = [])
    {
        $requestId = (string) Str::uuid();
        $level     = $status >= 500 ? 'error' : 'warning';

        Log::{$level}('LaraKnow AI package request failed', [
            'request_id'      => $requestId,
            'endpoint'        => $endpoint,
            'status'          => $status,
            'exception'       => $e::class,
            'message'         => $e->getMessage(),
            'file'            => $e->getFile(),
            'line'            => $e->getLine(),
            'user_id'         => optional(Auth::user())->id,
            'conversation_id' => $this->conversationService->requestConversationId($request),
            'route'           => optional($request?->route())->getName(),
            'method'          => $request?->method(),
            'url'             => $request?->fullUrl(),
            'prompt_length'   => is_string($request?->input('prompt')) ? strlen($request->input('prompt')) : null,
            'trace'           => $e->getTraceAsString(),
        ]);

        $payload = [
            'message'    => $message,
            'reply'      => $message,
            'data'       => [],
            'suggestion' => $extra['suggestion'] ?? 'Please try again in a moment, or contact support if the issue persists.',
            'request_id' => $requestId,
            'error'      => [
                'code'       => $status,
                'type'       => class_basename($e),
                'request_id' => $requestId,
            ],
        ];

        if (isset($extra['errors'])) {
            $payload['errors'] = $extra['errors'];
        }

        if (config('app.debug') && $status !== 429) {
            $payload['error']['detail'] = $e->getMessage();
            $payload['error']['file']   = $e->getFile();
            $payload['error']['line']   = $e->getLine();
        }

        $this->auditEvent('request.failure', [
            'request_id'      => $requestId,
            'endpoint'        => $endpoint,
            'status'          => $status,
            'exception'       => $e::class,
            'message'         => $e->getMessage(),
            'user_id'         => optional(Auth::user())->id,
            'conversation_id' => $this->conversationService->requestConversationId($request),
            'route'           => optional($request?->route())->getName(),
            'method'          => $request?->method(),
            'url'             => $request?->fullUrl(),
            'prompt_length'   => is_string($request?->input('prompt')) ? strlen($request->input('prompt')) : null,
        ], $status >= 500 ? 'error' : 'warning');

        return response()
            ->json($payload, $status)
            ->header('X-LaraKnow-Request-Id', $requestId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return a provider-neutral message for AI rate limit responses.
     */
    private function rateLimitMessage(): string
    {
        return (string) config(
            'laraknow.ui.messages.rate_limited',
            'Something went wrong. Please try again in a moment or contact admin.'
        );
    }

    /**
     * Return a stable response when Laravel AI conversation tables are missing.
     *
     * @param  string  $endpoint
     * @param  Request|null  $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function conversationStorageUnavailableResponse(string $endpoint, ?Request $request)
    {
        $missingTables = $this->missingConversationStorageTables();

        if (empty($missingTables)) {
            return null;
        }

        return $this->logAndRespond(
            $endpoint,
            $request,
            new \RuntimeException('Missing LaraKnow AI conversation tables: '.implode(', ', $missingTables)),
            503,
            'The assistant storage is not ready. Please run the required migrations.',
            [
                'suggestion'     => 'Run php artisan migrate, then try again.',
                'missing_tables' => $missingTables,
            ]
        );
    }

    /**
     * Resolve missing Laravel AI conversation tables without assuming host names.
     *
     * @return array<int, string>
     */
    private function missingConversationStorageTables(): array
    {
        $tables = array_values(array_unique(array_filter([
            config('ai.conversations.tables.conversations', 'agent_conversations'),
            config('ai.conversations.tables.messages', 'agent_conversation_messages'),
        ])));

        return array_values(array_filter($tables, fn (string $table) => ! Schema::hasTable($table)));
    }

    /**
     * Safely record an audit event without crashing the request.
     *
     * @param  string  $event
     * @param  array<string, mixed>  $context
     * @param  string  $level
     */
    private function auditEvent(string $event, array $context = [], string $level = 'info'): void
    {
        try {
            (new AuditLogger)->record($event, $context, $level);
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI audit event failed', [
                'event'     => $event,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize stored Laravel AI message content into displayable text.
     *
     * @param  string  $content
     * @param  PromptSecurity  $promptSecurity
     * @return string
     */
    private function messageContent(string $content, PromptSecurity $promptSecurity): string
    {
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->stripUserContext($promptSecurity->stripIsolation($this->cleanText($content)));
        }

        if (is_string($decoded)) {
            return $this->stripUserContext($promptSecurity->stripIsolation($this->cleanText($decoded)));
        }

        if (is_array($decoded)) {
            if (isset($decoded['text']) && is_string($decoded['text'])) {
                return $this->stripUserContext($promptSecurity->stripIsolation($this->cleanText($decoded['text'])));
            }

            $parts = [];

            foreach ($decoded as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                } elseif (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                    $parts[] = $item['text'];
                } elseif (is_array($item) && isset($item['content']) && is_string($item['content'])) {
                    $parts[] = $item['content'];
                }
            }

            return $this->stripUserContext($promptSecurity->stripIsolation($this->cleanText(implode("\n", $parts))));
        }

        return '';
    }

    /**
     * Remove internal user context that older stored prompts may include.
     */
    private function stripUserContext(string $text): string
    {
        return trim(preg_replace('/\n{0,2}User Context:\s*\{.*\}\s*$/su', '', $text) ?? $text);
    }

    /**
     * Normalize whitespace while keeping readable line breaks.
     */
    private function cleanText(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\r\n", "\r"], [' ', "\n", "\n"], $text);

        $lines = array_map(
            fn ($line) => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line),
            explode("\n", $text)
        );

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? $text);
    }
}
