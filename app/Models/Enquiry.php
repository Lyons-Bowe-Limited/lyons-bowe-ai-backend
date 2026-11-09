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
        'priority_source',
        'priority_reason',
        'priority_confidence',
        'priority_set_at',
        'priority_set_by_staff_id',
        'client_name',
        'client_email',
        'client_phone',
        'office',
        'recommended_service',
        'completion_percentage',
        'assigned_to', // Legacy client-user assignment; internal assignment uses assigned_staff_id.
        'assigned_staff_id',
        'matter_type',
        'qualified_at',
        'classification_confidence',
        'classification_source',
        'requires_review',
        'handoff_reason',
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
            'priority_set_at' => 'datetime',
            'priority_confidence' => 'float',
            'priority_set_by_staff_id' => 'integer',
            'qualified_at' => 'datetime',
            'classification_confidence' => 'float',
            'requires_review' => 'boolean',
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

    /** Legacy client-user relationship. Use assignedStaff for internal assignment. */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_to'
        );
    }

    public function prioritySetByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'priority_set_by_staff_id');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'assigned_staff_id');
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
