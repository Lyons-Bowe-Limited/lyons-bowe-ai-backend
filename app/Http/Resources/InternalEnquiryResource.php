<?php

namespace App\Http\Resources;

use App\Http\Resources\Internal\ConversationMessageResource;
use App\Http\Resources\Internal\EnquiryActivityResource;
use App\Http\Resources\Internal\EnquiryListItemResource;
use App\Services\Enquiries\OperationalEnquirySummary;
use Illuminate\Http\Request;

class InternalEnquiryResource extends EnquiryListItemResource
{
    public function toArray(Request $request): array
    {
        $conversation = $this->conversation;
        $memory = $conversation?->memory;
        $user = $this->user;
        $workflow = $this->workflow;

        return [
            ...parent::toArray($request),
            'priority_set_by_staff_id' => $this->priority_set_by_staff_id,
            'user_id' => $this->user_id, 'conversation_id' => $this->conversation_id,
            'assigned_to' => $this->assigned_to, // Legacy client-user ID; never internal assignment.
            'recommended_service' => $this->recommended_service, 'completion_percentage' => $this->completion_percentage,
            'qualified_at' => $this->qualified_at, 'started_at' => $this->started_at,
            'completed_at' => $this->completed_at, 'updated_at' => $this->updated_at,
            'client_account' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'contact_number' => $user->contact_number, 'email_verified_at' => $user->email_verified_at] : null,
            'classification' => ['practice_area' => $this->practice_area, 'matter_type' => $this->matter_type,
                'confidence' => $this->classification_confidence, 'source' => $this->classification_source,
                'requires_review' => $this->requires_review, 'handoff_reason' => $this->handoff_reason],
            'conversation' => $conversation ? [
                'id' => $conversation->id, 'uuid' => $conversation->uuid, 'status' => $conversation->status,
                'summary' => app(OperationalEnquirySummary::class)->for($this->resource),
                // Historical AI text is explicitly distinct from the current operational summary.
                'original_ai_summary' => $memory?->summary,
                'conversation_stage' => $memory?->conversation_stage,
                'messages' => ConversationMessageResource::collection($conversation->messages),
                'created_at' => $conversation->created_at, 'updated_at' => $conversation->updated_at,
            ] : null,
            'events' => EnquiryActivityResource::collection($this->events),
            'workflow' => $workflow ? [
                'id' => $workflow->id, 'workflow_key' => $workflow->workflow_key, 'workflow_version' => $workflow->workflow_version,
                'status' => $workflow->status, 'current_step_key' => $workflow->current_step_key,
                'answered_steps' => $workflow->answered_steps, 'total_applicable_steps' => $workflow->total_applicable_steps,
                'started_at' => $workflow->started_at, 'completed_at' => $workflow->completed_at, 'last_activity_at' => $workflow->last_activity_at,
            ] : null,
        ];
    }
}
