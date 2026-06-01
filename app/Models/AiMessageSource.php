<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessageSource extends Model
{
    protected $fillable = [
        'ai_message_id',
        'knowledge_document_id',
    ];
}
