<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnquiryAnswer extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
         'enquiry_id',
        'workflow_id',

        'step_key',

        'question_key',
        'question_text',
        'answer_type',

        'answer',
        'normalised_answer',
        'metadata',

        'revision',

        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'answer' => 'array',
            'normalised_answer' => 'array',
            'metadata' => 'array',
            'revision' => 'integer',
            'answered_at' => 'datetime',
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
}