<?php

namespace App\Services\Enquiries;

use App\Enums\PracticeArea;
use App\Models\Enquiry;
use App\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InternalEnquiryService
{
    public function __construct(private readonly EnquiryEventService $events) {}

    public function assign(Enquiry $enquiry, ?int $staffId, StaffUser $actor): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $staffId, $actor) {
            $enquiry = Enquiry::whereKey($enquiry->id)->lockForUpdate()->firstOrFail();
            if ($staffId !== null) {
                $staff = StaffUser::whereKey($staffId)->lockForUpdate()->first();
                if (! $staff || $staff->status !== StaffUser::STATUS_ACTIVE) {
                    throw ValidationException::withMessages(['assigned_staff_id' => 'The assignee must be an active staff user.']);
                }
            }
            $previous = $enquiry->assigned_staff_id;
            if ($previous === $staffId) {
                return $enquiry;
            }
            $enquiry->update(['assigned_staff_id' => $staffId, 'last_activity_at' => now()]);
            $type = $staffId === null ? EnquiryEventService::ENQUIRY_UNASSIGNED
                : ($previous === null ? EnquiryEventService::ENQUIRY_ASSIGNED : EnquiryEventService::ENQUIRY_REASSIGNED);
            $this->events->staffChange($enquiry, $actor, $type, ['assigned_staff_id' => $previous], ['assigned_staff_id' => $staffId]);

            return $enquiry;
        }, attempts: 3);
    }

    public function correctClassification(Enquiry $enquiry, array $data, StaffUser $actor): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $data, $actor) {
            $enquiry = Enquiry::whereKey($enquiry->id)->lockForUpdate()->firstOrFail();
            if (! app(EnquiryClassification::class)->isValid($data['practice_area'], $data['matter_type'])) {
                throw ValidationException::withMessages(['matter_type' => 'The matter type does not belong to the selected practice area.']);
            }
            if ($data['practice_area'] === PracticeArea::Unclassified->value && ! $data['requires_review']) {
                throw ValidationException::withMessages(['requires_review' => 'Unclassified enquiries require review.']);
            }
            $summary = app(OperationalEnquirySummary::class)->for($enquiry);
            if (! $data['requires_review'] && ! $summary['contact_details_complete']) {
                throw ValidationException::withMessages(['requires_review' => 'Contact details are incomplete; retain review until they are available.']);
            }
            $data['classification_source'] = 'staff';
            $data['classification_confidence'] = null;
            // A staff judgement has no new numeric AI confidence score.
            $data['handoff_reason'] = $data['handoff_reason'] ?? ($data['requires_review']
                ? ($data['practice_area'] === PracticeArea::Unclassified->value ? 'classification_requires_review' : 'staff_review_required')
                : 'staff_classification_confirmed');
            $fields = ['practice_area', 'matter_type', 'classification_source', 'classification_confidence', 'requires_review', 'handoff_reason'];
            $before = $enquiry->only($fields);
            $enquiry->fill($data);
            if (! $enquiry->isDirty($fields)) {
                return $enquiry;
            }
            $enquiry->last_activity_at = now();
            $enquiry->save();
            $this->events->staffChange($enquiry, $actor, EnquiryEventService::CLASSIFICATION_CORRECTED, $before, $enquiry->only($fields));

            return $enquiry;
        }, attempts: 3);
    }
}
