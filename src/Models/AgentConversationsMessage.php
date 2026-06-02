<?php

namespace Webbycrown\LaraknowAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for messages stored inside an AI assistant conversation.
 *
 * The model stores user and assistant messages, tool calls, tool results,
 * token usage, and optional metadata for later conversation recall.
 */
class AgentConversationsMessage extends Model
{
    /**
     * The database table associated with the model.
     *
     * @var string
     */
    protected $table = 'agent_conversation_messages';

    /**
     * Indicates if the model uses an auto-incrementing primary key.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'conversation_id',
        'user_id',
        'agent',
        'role',
        'content',
        'attachments',
        'tool_calls',
        'tool_results',
        'usage',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'attachments' => 'array',
        'tool_calls' => 'array',
        'tool_results' => 'array',
        'usage' => 'array',
        'meta' => 'array',
    ];

    /**
     * Get the conversation that owns this message.
     *
     * @return BelongsTo
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversations::class, 'conversation_id');
    }

    public function getTable()
    {
        return config('ai.conversations.tables.messages', $this->table);
    }

    /**
     * Get token usage details with a safe default structure.
     *
     * @return array<string, int>
     */
    public function getTokenUsageAttribute(): array
    {
        return $this->usage ?? [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];
    }
}
