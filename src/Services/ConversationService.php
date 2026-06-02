<?php

namespace Webbycrown\LaraknowAi\Services;

use Webbycrown\LaraknowAi\Models\AgentConversations;
use Webbycrown\LaraknowAi\Models\AgentConversationsMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ConversationService
 *
 * Manages conversation lifecycle: creating, resolving, repairing, and
 * persisting conversation records. Host-project neutral — relies only on
 * package models and Laravel config values.
 */
class ConversationService
{
    /**
     * Resolve a conversation ID from the request (input or session).
     *
     * @param  Request|null  $request
     * @return mixed
     */
    public function requestConversationId(?Request $request): mixed
    {
        if (! $request) {
            return null;
        }

        if ($conversationId = $request->input('conversation_id')) {
            return $conversationId;
        }

        return $request->hasSession() ? $request->session()->get('ai_conversation_id') : null;
    }

    /**
     * Store the active conversation ID in the session (if a session exists).
     *
     * @param  Request  $request
     * @param  mixed  $conversationId
     */
    public function rememberConversationId(Request $request, mixed $conversationId): void
    {
        if (! $request->hasSession() || ! $conversationId) {
            return;
        }

        $request->session()->put('ai_conversation_id', $conversationId);
    }

    /**
     * Remove the stored conversation ID from the session.
     *
     * @param  Request  $request
     */
    public function forgetConversationId(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget('ai_conversation_id');
    }

    /**
     * Resolve a safe conversation row for the current user.
     *
     * If a matching row already exists it is returned (and its title updated
     * when still a placeholder). If the conversation ID has orphaned messages
     * but no parent row, the parent row is re-created. Otherwise a fresh row
     * is created.
     *
     * @param  int|string  $userId
     * @param  string|null  $conversationId
     * @param  string  $title
     * @return AgentConversations
     */
    public function ensureConversationForUser($userId, ?string $conversationId, string $title): AgentConversations
    {
        if ($conversationId) {
            $conversation = AgentConversations::query()
                ->where('user_id', $userId)
                ->whereKey($conversationId)
                ->first();

            if ($conversation) {
                if (in_array($conversation->title, ['New conversation', 'Untitled chat'], true)) {
                    $conversation->forceFill(['title' => $this->conversationTitle($title)])->save();
                }

                return $conversation;
            }

            $hasOwnedMessages = AgentConversationsMessage::query()
                ->where('conversation_id', $conversationId)
                ->where('user_id', $userId)
                ->exists();

            if ($hasOwnedMessages) {
                return $this->createConversationRecord($userId, $title, $conversationId);
            }
        }

        return $this->createConversationRecord($userId, $title);
    }

    /**
     * Create a parent conversation row for the user.
     *
     * @param  int|string  $userId
     * @param  string  $title
     * @param  string|null  $conversationId
     * @return AgentConversations
     */
    public function createConversationRecord($userId, string $title, ?string $conversationId = null): AgentConversations
    {
        $conversation = new AgentConversations([
            'id'      => $conversationId ?: (string) Str::uuid(),
            'user_id' => $userId,
            'title'   => $this->conversationTitle($title),
        ]);

        $conversation->save();

        return $conversation;
    }

    /**
     * Backfill missing parent rows for already-stored message threads.
     *
     * @param  int|string  $userId
     */
    public function repairMissingConversationsForUser($userId): void
    {
        $conversationTable = $this->conversationTable();
        $messageTable      = $this->conversationMessageTable();

        $orphans = AgentConversationsMessage::query()
            ->select('conversation_id')
            ->selectRaw('MIN(created_at) as first_created_at')
            ->selectRaw('MAX(updated_at) as last_updated_at')
            ->where('user_id', $userId)
            ->whereNotNull('conversation_id')
            ->whereNotExists(function ($query) use ($conversationTable, $messageTable) {
                $query->selectRaw('1')
                    ->from($conversationTable)
                    ->whereColumn($conversationTable.'.id', $messageTable.'.conversation_id');
            })
            ->groupBy('conversation_id')
            ->limit(50)
            ->get();

        foreach ($orphans as $orphan) {
            $firstPrompt = AgentConversationsMessage::query()
                ->where('conversation_id', $orphan->conversation_id)
                ->where('user_id', $userId)
                ->where('role', 'user')
                ->orderBy('created_at')
                ->value('content') ?: 'Recovered conversation';

            $conversation             = new AgentConversations([
                'id'      => $orphan->conversation_id,
                'user_id' => $userId,
                'title'   => $this->conversationTitle($firstPrompt),
            ]);
            $conversation->created_at = $orphan->first_created_at ?: now();
            $conversation->updated_at = $orphan->last_updated_at ?: now();
            $conversation->save();
        }
    }

    /**
     * Return a normalised sidebar payload for a conversation.
     *
     * @param  AgentConversations  $conversation
     * @return array<string, mixed>
     */
    public function conversationPayload(AgentConversations $conversation): array
    {
        return [
            'id'              => $conversation->id,
            'title'           => $conversation->title ?: 'Untitled chat',
            'messages_count'  => $conversation->messages_count ?? $conversation->messages()->count(),
            'updated_at'      => optional($conversation->updated_at)->toIso8601String(),
            'updated_at_label' => optional($conversation->updated_at)->diffForHumans(),
        ];
    }

    /**
     * Build a compact sidebar title from prompt text.
     *
     * @param  string  $title
     * @return string
     */
    public function conversationTitle(string $title): string
    {
        return Str::limit(trim(strip_tags($title)) ?: 'New conversation', 100, preserveWords: true);
    }

    /**
     * @return string
     */
    public function conversationTable(): string
    {
        return (string) config('ai.conversations.tables.conversations', 'agent_conversations');
    }

    /**
     * @return string
     */
    public function conversationMessageTable(): string
    {
        return (string) config('ai.conversations.tables.messages', 'agent_conversation_messages');
    }
}
