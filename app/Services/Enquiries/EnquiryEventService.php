<?php

namespace App\Services\Enquiries;

use App\Models\Enquiry;
use App\Models\EnquiryEvent;
use App\Models\EnquiryWorkflow;

class EnquiryEventService
{
    public const WORKFLOW_STARTED = 'workflow_started';

    public const ANSWER_RECORDED = 'answer_recorded';

    public const STEP_CHANGED = 'step_changed';

    public const STATUS_CHANGED = 'status_changed';

    public const WORKFLOW_COMPLETED = 'workflow_completed';

    public const ENQUIRY_ASSIGNED = 'enquiry_assigned';

    public const SUMMARY_GENERATED = 'summary_generated';

    /**
     * Record that an enquiry workflow has started.
     */
    public function workflowStarted(
        Enquiry $enquiry,
        EnquiryWorkflow $workflow,
        ?int $performedBy = null,
        array $metadata = []
    ): EnquiryEvent {
        return $this->record(
            enquiry: $enquiry,
            workflow: $workflow,
            eventType: self::WORKFLOW_STARTED,
            performedBy: $performedBy,
            toStatus: $workflow->status,
            stepKey: $workflow->current_step_key,
            metadata: [
                'workflow_key' => $workflow->workflow_key,
                'workflow_version' => $workflow->workflow_version,
                ...$metadata,
            ],
        );
    }

    /**
     * Record that an answer has been saved.
     */
    public function answerRecorded(
        Enquiry $enquiry,
        EnquiryWorkflow $workflow,
        string $stepKey,
        string $questionKey,
        mixed $answer,
        int $revision,
        ?int $performedBy = null,
        array $metadata = []
    ): EnquiryEvent {
        return $this->record(
            enquiry: $enquiry,
            workflow: $workflow,
            eventType: self::ANSWER_RECORDED,
            performedBy: $performedBy,
            stepKey: $stepKey,
            metadata: [
                'question_key' => $questionKey,
                'answer' => $answer,
                'revision' => $revision,
                ...$metadata,
            ],
        );
    }

    /**
     * Record movement from one workflow step to another.
     */
    public function stepChanged(
        Enquiry $enquiry,
        EnquiryWorkflow $workflow,
        string $fromStepKey,
        ?string $toStepKey,
        ?int $performedBy = null,
        array $metadata = []
    ): EnquiryEvent {
        return $this->record(
            enquiry: $enquiry,
            workflow: $workflow,
            eventType: self::STEP_CHANGED,
            performedBy: $performedBy,
            stepKey: $toStepKey,
            metadata: [
                'from_step_key' => $fromStepKey,
                'to_step_key' => $toStepKey,
                ...$metadata,
            ],
        );
    }

    /**
     * Record a status transition.
     */
    public function statusChanged(
        Enquiry $enquiry,
        ?EnquiryWorkflow $workflow,
        ?string $fromStatus,
        string $toStatus,
        ?int $performedBy = null,
        array $metadata = []
    ): EnquiryEvent {
        return $this->record(
            enquiry: $enquiry,
            workflow: $workflow,
            eventType: self::STATUS_CHANGED,
            performedBy: $performedBy,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            stepKey: $workflow?->current_step_key,
            metadata: $metadata,
        );
    }

    /**
     * Record that the workflow has been completed.
     */
    public function workflowCompleted(
        Enquiry $enquiry,
        EnquiryWorkflow $workflow,
        ?int $performedBy = null,
        array $metadata = []
    ): EnquiryEvent {
        return $this->record(
            enquiry: $enquiry,
            workflow: $workflow,
            eventType: self::WORKFLOW_COMPLETED,
            performedBy: $performedBy,
            fromStatus: 'in_progress',
            toStatus: $workflow->status,
            metadata: [
                'answered_steps' => $workflow->answered_steps,
                'total_applicable_steps' =>
                    $workflow->total_applicable_steps,
                'completion_percentage' =>
                    $enquiry->completion_percentage,
                ...$metadata,
            ],
        );
    }

    /**
     * Record assignment to a staff member.
     */
    public function assigned(
        Enquiry $enquiry,
        ?EnquiryWorkflow $workflow,
        int $assignedTo,
        ?int $previouslyAssignedTo = null,
        ?int $performedBy = null,
        array $metadata = []
    ): EnquiryEvent {
        return $this->record(
            enquiry: $enquiry,
            workflow: $workflow,
            eventType: self::ENQUIRY_ASSIGNED,
            performedBy: $performedBy,
            stepKey: $workflow?->current_step_key,
            metadata: [
                'previously_assigned_to' => $previouslyAssignedTo,
                'assigned_to' => $assignedTo,
                ...$metadata,
            ],
        );
    }

    /**
     * Record that an enquiry summary has been generated.
     */
    public function summaryGenerated(
        Enquiry $enquiry,
        ?EnquiryWorkflow $workflow,
        ?int $performedBy = null,
        array $metadata = []
    ): EnquiryEvent {
        return $this->record(
            enquiry: $enquiry,
            workflow: $workflow,
            eventType: self::SUMMARY_GENERATED,
            performedBy: $performedBy,
            stepKey: $workflow?->current_step_key,
            metadata: $metadata,
        );
    }

    /**
     * Persist an enquiry event.
     */
    private function record(
        Enquiry $enquiry,
        ?EnquiryWorkflow $workflow,
        string $eventType,
        ?int $performedBy = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $stepKey = null,
        array $metadata = []
    ): EnquiryEvent {
        return EnquiryEvent::query()->create([
            'enquiry_id' => $enquiry->id,
            'workflow_id' => $workflow?->id,
            'performed_by' => $performedBy,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'step_key' => $stepKey,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}