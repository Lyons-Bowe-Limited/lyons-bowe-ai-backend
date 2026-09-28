<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Enquiry extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'conversation_id',
        'reference',
        'practice_area',
        'workflow_key',
        'status',
        'priority',
        'client_name',
        'client_email',
        'client_phone',
        'office',
        'recommended_service',
        'completion_percentage',
        'assigned_to',
        'summary',
        'metadata',
        'started_at',
        'submitted_at',
        'completed_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'metadata' => 'array',
            'completion_percentage' => 'integer',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(
            AiConversation::class,
            'conversation_id'
        );
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_to'
        );
    }

    public function workflow(): HasOne
    {
        return $this->hasOne(EnquiryWorkflow::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EnquiryAnswer::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EnquiryEvent::class);
    }
}