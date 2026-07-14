<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiConversation extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'title',
        'status',
        'is_starred',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_starred' => 'boolean',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(
            AiMessage::class,
            'ai_conversation_id'
        );
    }

    public function memory(): HasOne
    {
        return $this->hasOne(
            AiConversationMemory::class,
            'ai_conversation_id'
        );
    }
}