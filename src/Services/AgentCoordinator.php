<?php

namespace Webbycrown\LaraknowAi\Services;

use Webbycrown\LaraknowAi\Ai\Agents\SummaryCoach;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AgentCoordinator
 *
 * Responsible for selecting, building, and running the AI agent instance.
 * Handles both authenticated users and anonymous guests in a host-project-neutral way.
 * Contains no project-specific logic; works with any Laravel host application.
 */
class AgentCoordinator
{
    /**
     * Run the AI agent for an authenticated user and return the raw response.
     *
     * @param  Authenticatable  $user
     * @param  string|null  $conversationId
     * @param  string  $isolatedPrompt
     * @param  string  $rawPrompt  Used for SummaryCoach context (latest_prompt).
     * @return mixed
     */
    public function runForUser(
        Authenticatable $user,
        ?string $conversationId,
        string $isolatedPrompt,
        string $rawPrompt
    ): mixed {
        try {
            return (new SummaryCoach($user, $conversationId, $rawPrompt))
                ->continue($conversationId, as: $user)
                ->prompt($isolatedPrompt);
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI AgentCoordinator::runForUser failed', [
                'exception'       => $e::class,
                'message'         => $e->getMessage(),
                'conversation_id' => $conversationId,
                'user_id'         => $user->getAuthIdentifier(),
            ]);

            throw $e;
        }
    }

    /**
     * Run the AI agent for an anonymous guest and return the raw response.
     *
     * @param  string|null  $conversationId
     * @param  string  $isolatedPrompt
     * @param  string  $rawPrompt  Used for SummaryCoach context (latest_prompt).
     * @return mixed
     */
    public function runForGuest(
        ?string $conversationId,
        string $isolatedPrompt,
        string $rawPrompt
    ): mixed {
        try {
            $guest = $this->guestConversationParticipant();
            $agent = new SummaryCoach(null, $conversationId, $rawPrompt);

            if ($conversationId) {
                $agent->continue($conversationId, as: $guest);
            } else {
                $agent->forUser($guest);
            }

            return $agent->prompt($isolatedPrompt);
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI AgentCoordinator::runForGuest failed', [
                'exception'       => $e::class,
                'message'         => $e->getMessage(),
                'conversation_id' => $conversationId,
            ]);

            throw $e;
        }
    }

    /**
     * Get an anonymous participant object for guest conversation memory.
     *
     * Laravel AI requires a conversation participant object to enable its
     * remember-conversation middleware. A null ID keeps guest messages stored
     * without assuming a project-specific users table or ID type.
     *
     * @return object{id:null}
     */
    public function guestConversationParticipant(): object
    {
        return new class
        {
            public $id = null;
        };
    }
}
