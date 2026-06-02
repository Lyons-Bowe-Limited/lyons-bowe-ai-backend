<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'title',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function messages()
    {
        return $this->hasMany(\App\Models\AiMessage::class);
    }
}
