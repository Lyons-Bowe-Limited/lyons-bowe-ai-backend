<?php

namespace App\Services\Enquiries;

use App\Enums\EnquiryPriority;
use App\Enums\PracticeArea;
use App\Models\AiConversationMemory;
use App\Models\Enquiry;
use App\Models\EnquiryEvent;
use App\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EnquiryPriorityService
{
    public function __construct(private readonly UrgencyDetectionService $urgency, private readonly EnquiryEventService $events) {}

    public function fromMemory(Enquiry $enquiry, AiConversationMemory $memory): Enquiry
    {
        $memory = $memory->fresh();
        if (! $memory || (int) $memory->ai_conversation_id !== (int) $enquiry->conversation_id) {
            throw new \InvalidArgumentException('Priority memory must belong to the enquiry conversation.');
        }
        $best = [EnquiryPriority::Normal, 'no_reliable_urgency_signal', 0.0];
        foreach ($this->urgency->normalise($memory->urgency)['signals'] as $signal) {
            if ($signal['type'] === 'medical_deadline' && $enquiry->practice_area !== PracticeArea::Wills->value) {
                continue;
            }
            if ($signal['confidence'] < config('enquiry-priority.confidence_threshold')) {
                continue;
            }
            $priority = null;
            if ($signal['type'] === 'explicit_urgent' || ($signal['type'] === 'immediate_safety' && $signal['immediate'] === true)) {
                $priority = EnquiryPriority::Urgent;
            } elseif ($deadline = $this->urgency->deadline($signal['deadline_at'])) {
                $now = CarbonImmutable::now();
                if ($deadline->greaterThanOrEqualTo($now)) {
                    $priority = $deadline->lessThanOrEqualTo($now->addHours(config('enquiry-priority.imminent_hours'))) ? EnquiryPriority::Urgent : EnquiryPriority::High;
                }
            } elseif (in_array($signal['type'], ['court_deadline', 'hearing_date', 'completion_deadline', 'medical_deadline', 'other_deadline', 'time_sensitive_circumstance'], true) && $signal['immediate'] !== null) {
                $priority = $signal['immediate'] ? EnquiryPriority::Urgent : EnquiryPriority::High;
            }
            if ($priority && ($priority->rank() > $best[0]->rank() || ($priority === $best[0] && $signal['confidence'] > $best[2]))) {
                $best = [$priority, $signal['type'], $signal['confidence']];
            }
        }

        return $this->raise($enquiry, ...$best);
    }

    public function fromWorkflowAnswers(Enquiry $enquiry): Enquiry
    {
        if ($enquiry->workflow_key !== 'will_enquiry_v1') {
            return $enquiry;
        }
        $answer = $enquiry->answers()->whereIn('question_key', config('enquiry-priority.urgent_will_keys'))
            ->orderByDesc('answered_at')->orderByDesc('revision')->first();
        $value = $answer?->normalised_answer['value'] ?? $answer?->answer['value'] ?? null;

        return $value === true ? $this->urgentWill($enquiry) : $enquiry;
    }

    public function urgentWill(Enquiry $enquiry): Enquiry
    {
        return $this->raise($enquiry, EnquiryPriority::Urgent, 'urgent_will_required', 1.0);
    }

    private function raise(Enquiry $enquiry, EnquiryPriority $priority, string $reason, float $confidence): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $priority, $reason, $confidence) {
            $enquiry = Enquiry::whereKey($enquiry->id)->lockForUpdate()->firstOrFail();
            if ($enquiry->priority_source === 'staff') {
                return $enquiry;
            }
            $previous = EnquiryPriority::tryFrom($enquiry->priority) ?? EnquiryPriority::Normal;
            if ($priority->rank() < $previous->rank() || ($priority === $previous && $enquiry->priority_source !== null)) {
                return $enquiry;
            }
            if ($priority === $previous && $priority !== EnquiryPriority::Normal) {
                return $enquiry;
            }
            $enquiry->update([
                'priority' => $priority->value, 'priority_source' => 'victoria', 'priority_reason' => $reason,
                'priority_confidence' => $confidence, 'priority_set_at' => now(), 'priority_set_by_staff_id' => null,
                'last_activity_at' => now(),
            ]);
            if ($priority->rank() > $previous->rank()) {
                EnquiryEvent::create([
                    'enquiry_id' => $enquiry->id, 'event_type' => EnquiryEventService::PRIORITY_RAISED,
                    'metadata' => ['before' => ['priority' => $previous->value], 'after' => [
                        'priority' => $priority->value, 'priority_source' => 'victoria', 'priority_reason' => $reason, 'priority_confidence' => $confidence,
                    ]],
                ]);
            }

            return $enquiry;
        }, attempts: 3);
    }

    public function override(Enquiry $enquiry, EnquiryPriority $priority, string $reason, StaffUser $actor): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $priority, $reason, $actor) {
            $enquiry = Enquiry::whereKey($enquiry->id)->lockForUpdate()->firstOrFail();
            if ($enquiry->priority_source === 'staff' && $enquiry->priority === $priority->value && $enquiry->priority_reason === $reason) {
                return $enquiry;
            }
            $fields = ['priority', 'priority_source', 'priority_reason', 'priority_confidence'];
            $before = $enquiry->only($fields);
            $enquiry->update([
                'priority' => $priority->value, 'priority_source' => 'staff', 'priority_reason' => $reason,
                'priority_confidence' => null, 'priority_set_at' => now(), 'priority_set_by_staff_id' => $actor->id,
                'last_activity_at' => now(),
            ]);
            $this->events->staffChange($enquiry, $actor, EnquiryEventService::PRIORITY_CHANGED_BY_STAFF, $before, $enquiry->only($fields));

            return $enquiry;
        }, attempts: 3);
    }
}
