<?php

namespace App\Data\Enquiries;

use App\Models\Enquiry;
use App\Models\EnquiryWorkflow;
use JsonSerializable;

readonly class StepResult implements JsonSerializable
{
    public function __construct(
        public Enquiry $enquiry,
        public EnquiryWorkflow $workflow,
        public ?string $currentStepKey,
        public ?array $currentStep,
        public bool $completed,
        public int $progress,
    ) {
    }

    public function toArray(): array
    {
        return [
            'enquiry' => [
                'id' => $this->enquiry->id,
                'reference' => $this->enquiry->reference,
                'practice_area' => $this->enquiry->practice_area,
                'workflow_key' => $this->enquiry->workflow_key,
                'status' => $this->enquiry->status,
                'priority' => $this->enquiry->priority,
                'completion_percentage' =>
                    $this->enquiry->completion_percentage,
                'started_at' => $this->enquiry->started_at,
                'completed_at' => $this->enquiry->completed_at,
                'last_activity_at' =>
                    $this->enquiry->last_activity_at,
            ],

            'workflow' => [
                'id' => $this->workflow->id,
                'workflow_key' => $this->workflow->workflow_key,
                'workflow_version' =>
                    $this->workflow->workflow_version,
                'status' => $this->workflow->status,
                'current_step_key' =>
                    $this->workflow->current_step_key,
                'answered_steps' =>
                    $this->workflow->answered_steps,
                'total_applicable_steps' =>
                    $this->workflow->total_applicable_steps,
            ],

            'current_step_key' => $this->currentStepKey,
            'current_step' => $this->currentStep,
            'completed' => $this->completed,
            'progress' => $this->progress,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}