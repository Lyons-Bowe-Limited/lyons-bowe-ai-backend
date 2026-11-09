<?php

namespace App\Http\Resources\Internal;

use App\Services\Enquiries\OperationalEnquirySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'reference' => $this->reference,
            'client_name' => $this->client_name, 'client_email' => $this->client_email, 'client_phone' => $this->client_phone,
            'practice_area' => $this->practice_area, 'matter_type' => $this->matter_type,
            'source' => $this->workflow_key === 'victoria_conversation' ? 'victoria' : 'workflow', 'workflow_key' => $this->workflow_key,
            'office' => $this->office, 'status' => $this->status, 'priority' => $this->priority,
            'assigned_staff_id' => $this->assigned_staff_id,
            'assigned_staff' => new AssignedStaffResource($this->whenLoaded('assignedStaff')),
            'requires_review' => $this->requires_review, 'classification_confidence' => $this->classification_confidence,
            'classification_source' => $this->classification_source, 'handoff_reason' => $this->handoff_reason,
            'summary' => app(OperationalEnquirySummary::class)->for($this->resource),
            'received_at' => $this->created_at, 'created_at' => $this->created_at, 'submitted_at' => $this->submitted_at,
            'last_activity_at' => $this->last_activity_at,
        ];
    }
}
