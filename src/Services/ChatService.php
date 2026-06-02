<?php

namespace Webbycrown\LaraknowAi\Services;

use Webbycrown\LaraknowAi\Support\AuditLogger;
use Webbycrown\LaraknowAi\Support\PromptComplexityGuard;
use Webbycrown\LaraknowAi\Support\PromptSecurity;
use Webbycrown\LaraknowAi\Services\AgentCoordinator;
use Webbycrown\LaraknowAi\Services\ConversationService;
use Webbycrown\LaraknowAi\Services\ResponseNormalizerService;
use Webbycrown\LaraknowAi\Services\ToolExecutionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ChatService
 *
 * Orchestrates the full chat request lifecycle:
 *   1. Sanitise & validate the incoming prompt.
 *   2. Resolve or create the active conversation.
 *   3. Delegate agent execution to AgentCoordinator.
 *   4. Normalise the AI response via ResponseNormalizerService.
 *   5. Persist the final assistant reply via ToolExecutionService.
 *   6. Return a clean JSON response.
 *
 * Contains no project-specific logic. Safe for any Laravel host application.
 */
class ChatService
{
    private AgentCoordinator $agentCoordinator;
    private ConversationService $conversationService;
    private ResponseNormalizerService $responseNormalizer;
    private ToolExecutionService $toolExecutionService;

    public function __construct(
        AgentCoordinator $agentCoordinator,
        ConversationService $conversationService,
        ResponseNormalizerService $responseNormalizer,
        ToolExecutionService $toolExecutionService
    ) {
        $this->agentCoordinator     = $agentCoordinator;
        $this->conversationService  = $conversationService;
        $this->responseNormalizer   = $responseNormalizer;
        $this->toolExecutionService = $toolExecutionService;
    }

    /**
     * Handle an incoming chat request.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request)
    {
        $promptSecurity = new PromptSecurity;
        $prompt         = $promptSecurity->sanitizeUserPrompt((string) $request->input('prompt'));

        // 1. Security gate – reject jailbreak / bypass attempts.
        if ($promptSecurity->shouldRejectPrompt($prompt)) {
            return $this->promptRejectedResponse([
                'message'    => $this->supportScopeRefusal(),
                'suggestion' => 'Please ask a normal application question without requesting hidden instructions or security bypasses.',
                'errors'     => ['prompt' => ['The prompt asks to bypass assistant safety or reveal hidden instructions.']],
            ], $prompt);
        }

        // 2. Complexity gate – reject overly broad or ambiguous prompts.
        $promptRejection = (new PromptComplexityGuard)->inspect($prompt);

        if ($promptRejection) {
            return $this->promptRejectedResponse($promptRejection, $prompt);
        }

        $user           = Auth::user();
        $conversationId = null;

        // 3. Resolve conversation ID (reset if explicitly requested).
        if (! $request->boolean('reset_conversation')) {
            $conversationId = $request->input('conversation_id')
                ?? $this->conversationService->requestConversationId($request);
        }

        // 4. Ensure a conversation record exists for authenticated users.
        if ($user) {
            $conversation   = $this->conversationService->ensureConversationForUser($user->id, $conversationId, $prompt);
            $conversationId = $conversation->id;
        }

        try {
            // 5. Run the AI agent.
            $isolatedPrompt = $promptSecurity->isolateUserPrompt($prompt);

            $response = $user
                ? $this->agentCoordinator->runForUser($user, $conversationId, $isolatedPrompt, $prompt)
                : $this->agentCoordinator->runForGuest($conversationId, $isolatedPrompt, $prompt);

            // 6. Persist resolved conversation ID in session (if available).
            $this->conversationService->rememberConversationId($request, $response->conversationId);

            // 7. Re-ensure conversation for authenticated users after agent responds
            //    (the agent may assign a new conversation ID for the first message).
            if ($user) {
                $this->conversationService->ensureConversationForUser($user->id, $response->conversationId, $prompt);
            }

            // 8. Build normalised payload.
            $payload = $this->buildPayload($response, $prompt);

            // 9. Persist the normalised reply to the conversation message store.
            $this->toolExecutionService->persistNormalizedAssistantReply($response, $payload);

            // 10. Audit the successful response.
            $this->auditResponse($payload, $response, $prompt, $user);

            return response()->json($payload);

        } catch (Throwable $e) {
            Log::warning('ChatService handle failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            $this->auditEvent('request.failure', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ], 'error');

            // Re-throw so the controller's safely() wrapper handles HTTP response.
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the normalised response payload from the raw AI response.
     *
     * @param  mixed  $response
     * @param  string  $prompt
     * @return array<string, mixed>
     */
    private function buildPayload($response, string $prompt): array
    {
        // Priority 1: formatter tool (response / json tool call).
        $formatterData = $this->toolExecutionService->finalFormatterData($response);

        if ($formatterData) {
            $payload = $this->responseNormalizer->withConversationId(
                $this->responseNormalizer->normalizeAiData($formatterData, $prompt),
                $response
            );

            $payload = $this->toolExecutionService->guardAgainstDatabaseToolErrorAnswer($payload, $response);
            $payload = $this->toolExecutionService->groundDatabaseBackedResponse($payload, $response, $prompt);

            return $this->responseNormalizer->sanitizeRateLimitPayload($payload);
        }

        // Priority 2: structured data field on the response.
        $structured = data_get($response, 'structured');

        if (! empty($structured)) {
            $payload = $this->responseNormalizer->withConversationId(
                $this->responseNormalizer->normalizeAiData($structured, $prompt),
                $response
            );

            $payload = $this->toolExecutionService->guardAgainstDatabaseToolErrorAnswer($payload, $response);
            $payload = $this->toolExecutionService->groundDatabaseBackedResponse($payload, $response, $prompt);

            return $this->responseNormalizer->sanitizeRateLimitPayload($payload);
        }

        // Priority 3: plain text response (may contain JSON).
        $text = trim($response->text);
        $data = $this->toolExecutionService->decodeJsonPayload($text);

        if ($data === null) {
            $data = [
                'reply'      => $text,
                'data'       => [],
                'suggestion' => null,
            ];
        }

        if (empty($data['data'])) {
            $data['data'] = $this->toolExecutionService->databaseToolData($response);
        }

        $payload = $this->responseNormalizer->withConversationId(
            $this->responseNormalizer->normalizeAiData($data, $prompt),
            $response
        );

        $payload = $this->toolExecutionService->guardAgainstDatabaseToolErrorAnswer($payload, $response);
        $payload = $this->toolExecutionService->groundDatabaseBackedResponse($payload, $response, $prompt);

        return $this->responseNormalizer->sanitizeRateLimitPayload($payload);
    }

    /**
     * Return a stable 422 response for rejected prompts.
     *
     * @param  array<string, mixed>  $rejection
     * @param  string|null  $prompt
     * @return \Illuminate\Http\JsonResponse
     */
    private function promptRejectedResponse(array $rejection, ?string $prompt = null)
    {
        $message    = (string) ($rejection['message'] ?? 'The prompt cannot be processed.');
        $suggestion = (string) ($rejection['suggestion'] ?? 'Please try again with a more specific question.');

        $this->auditEvent('chat.prompt_rejected', [
            'prompt'     => $prompt,
            'message'    => $message,
            'errors'     => $rejection['errors'] ?? null,
            'suggestion' => $suggestion,
        ], 'warning');

        return response()->json([
            'message'    => $message,
            'reply'      => $message,
            'data'       => [],
            'suggestion' => $suggestion,
            'errors'     => $rejection['errors'] ?? ['prompt' => [$message]],
            'error'      => ['code' => 422, 'type' => 'PromptRejected'],
        ], 422);
    }

    /**
     * Short customer-facing refusal for security / internal probing.
     */
    private function supportScopeRefusal(): string
    {
        return "I appreciate you testing the system's boundaries, but I'm not able to engage with this request. I'm LaraKnow, here to help with questions within the assistant's available knowledge. How can I help you today?";
    }

    /**
     * Record a successful response in the audit log.
     *
     * @param  array<string, mixed>  $payload
     * @param  mixed  $response
     * @param  string  $prompt
     * @param  mixed  $user
     */
    private function auditResponse(array $payload, $response, string $prompt, $user): void
    {
        $this->auditEvent('chat.responded', [
            'user_id'         => optional($user)->id,
            'conversation_id' => $response->conversationId ?? null,
            'prompt_length'   => strlen($prompt),
            'reply'           => is_string($payload['reply'] ?? null) ? $payload['reply'] : null,
            'data_count'      => isset($payload['data']) && is_array($payload['data']) ? count($payload['data']) : null,
            'response_type'   => property_exists($response, 'structured') && ! empty($response->structured) ? 'structured' : 'text',
        ]);
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
}
