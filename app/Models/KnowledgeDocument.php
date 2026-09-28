<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeDocument extends Model
{
    protected $fillable = [
        'uuid',
        'title',
        'slug',
        'practice_area',
        'category',
        'summary',
        'content',
        'status',
        'visibility',
        'source_type',
        'source_reference',
        'tags',
        'metadata',
        'version',
    ];
    
    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
    ];
}
