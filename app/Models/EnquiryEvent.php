<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnquiryEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'enquiry_id',
        'workflow_id',
        'performed_by',
        'event_type',
        'from_status',
        'to_status',
        'step_key',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(
            EnquiryWorkflow::class,
            'workflow_id'
        );
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'performed_by'
        );
    }
}