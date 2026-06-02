<?php

namespace Webbycrown\LaraknowAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for AI assistant conversations.
 *
 * Each record represents a single conversation thread owned by a user and
 * connected to one or more stored conversation messages.
 */
class AgentConversations extends Model
{
    /**
     * The database table associated with the model.
     *
     * @var string
     */
    protected $table = 'agent_conversations';

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
        'user_id',
        'title',
    ];

    /**
     * Get the messages that belong to this conversation.
     *
     * @return HasMany
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentConversationsMessage::class, 'conversation_id');
    }

    public function getTable()
    {
        return config('ai.conversations.tables.conversations', $this->table);
    }

    /**
     * Get the user that owns this conversation.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo($this->userModel());
    }

    private function userModel(): string
    {
        $model = config('auth.providers.users.model') ?: config('auth.model');

        return is_string($model) && class_exists($model)
            ? $model
            : \Illuminate\Foundation\Auth\User::class;
    }
}
