<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversationMemory extends Model
{
    protected $fillable = [
        'uuid',
        'ai_conversation_id',
        'practice_area',
        'matter_type',
        'conversation_stage',
        'intent',
        'summary',
        'entities',
        'practice_area_confidence',
        'intent_confidence',
        'consultation_recommended',
        'booking_presented',
        'metadata',
    ];

    protected $casts = [
        'entities' => 'array',
        'metadata' => 'array',
        'practice_area_confidence' => 'float',
        'intent_confidence' => 'float',
        'consultation_recommended' => 'boolean',
        'booking_presented' => 'boolean',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(
            AiConversation::class,
            'ai_conversation_id'
        );
    }
}