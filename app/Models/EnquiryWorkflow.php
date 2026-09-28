<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnquiryWorkflow extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'enquiry_id',
        'workflow_key',
        'workflow_version',
        'status',
        'current_step_key',
        'previous_step_key',
        'answered_steps',
        'total_applicable_steps',
        'state',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'answered_steps' => 'integer',
            'total_applicable_steps' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(
            EnquiryAnswer::class,
            'workflow_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            EnquiryEvent::class,
            'workflow_id'
        );
    }
}