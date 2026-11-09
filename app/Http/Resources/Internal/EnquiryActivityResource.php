<?php

namespace App\Http\Resources\Internal;

use App\Services\Enquiries\EnquiryEventService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fields = match ($this->event_type) {
            EnquiryEventService::PRIORITY_RAISED, EnquiryEventService::PRIORITY_CHANGED_BY_STAFF => ['priority', 'priority_source', 'priority_reason', 'priority_confidence'],
            EnquiryEventService::ENQUIRY_ASSIGNED, EnquiryEventService::ENQUIRY_REASSIGNED, EnquiryEventService::ENQUIRY_UNASSIGNED => ['assigned_staff_id'],
            EnquiryEventService::CLASSIFICATION_CORRECTED => ['practice_area', 'matter_type', 'classification_source', 'classification_confidence', 'requires_review', 'handoff_reason'],
            default => [],
        };
        $safe = fn ($values) => collect(is_array($values) ? $values : [])->only($fields)->filter(fn ($value) => is_scalar($value) || $value === null)->all();

        return [
            'id' => $this->id, 'enquiry_id' => $this->enquiry_id, 'event_type' => $this->event_type,
            'actor_staff' => new AssignedStaffResource($this->whenLoaded('performedByStaff')),
            'actor_staff_id' => $this->performed_by_staff_id ?? (is_int($this->metadata['actor_staff_id'] ?? null) ? $this->metadata['actor_staff_id'] : null), 'actor_client_id' => $this->performed_by,
            'before' => $safe($this->metadata['before'] ?? []), 'after' => $safe($this->metadata['after'] ?? []),
            'from_status' => $this->from_status, 'to_status' => $this->to_status, 'step_key' => $this->step_key,
            'created_at' => $this->created_at,
        ];
    }
}
