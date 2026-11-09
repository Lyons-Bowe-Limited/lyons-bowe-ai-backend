<?php

namespace App\Services\Enquiries;

use App\Data\Enquiries\QualificationResult;
use App\Enums\PracticeArea;
use App\Models\AiConversation;
use App\Models\AiConversationMemory;
use App\Models\Enquiry;
use Illuminate\Support\Facades\DB;

class EnquiryQualificationService
{
    public function __construct(
        private readonly EnquiryCreationService $creation,
        private readonly EnquiryEventService $events,
    ) {}

    public function decide(AiConversation $conversation, ?AiConversationMemory $memory, array $consultation = [], bool $willCompleted = false, ?Enquiry $enquiry = null): QualificationResult
    {
        // Never classify from a caller's stale or unsaved model attributes.
        $memory = $memory?->fresh();
        if ($memory && (int) $memory->ai_conversation_id !== (int) $conversation->id) {
            throw new \InvalidArgumentException('Memory does not belong to this conversation.');
        }
        $enquiry ??= $conversation->enquiry()->first();
        $contacts = $this->contacts($conversation, $memory, $enquiry);
        $contactComplete = $this->hasContact(...array_values($contacts));
        $area = PracticeArea::normalise($memory?->practice_area);
        $matter = $this->matterType($memory?->matter_type);
        $confidence = max(0, min(1, (float) ($memory?->practice_area_confidence ?? 0)));
        $source = 'victoria';

        if ($willCompleted) {
            $area = PracticeArea::Wills;
            $matter = 'making_a_will';
            $confidence = 1;
            $source = 'workflow';
        }

        // Staff classification is authoritative before evaluating readiness,
        // including specific staff matter types outside the AI mapping.
        if ($enquiry?->classification_source === 'staff') {
            $area = PracticeArea::normalise($enquiry->practice_area);
            $matter = $enquiry->matter_type;
            $confidence = (float) ($enquiry->classification_confidence ?? 1);
            $source = 'staff';
        }
        $classificationValid = $area !== PracticeArea::Unclassified && filled($matter)
            && ($source === 'staff' || config("enquiries.matter_types.{$matter}.practice_area") === $area->value);
        $classificationConfident = $source === 'staff' || $confidence >= config('enquiries.classification_confidence_threshold');
        $explicitRequest = in_array($consultation['reason'] ?? null, config('enquiries.explicit_consultation_reasons'), true);
        $recognisedIntent = in_array(PracticeArea::key($memory?->intent), config('enquiries.legal_intents'), true);
        $intentConfident = (float) ($memory?->intent_confidence ?? 0) >= config('enquiries.intent_confidence_threshold');
        $recommended = $consultation['recommend'] ?? $memory?->consultation_recommended ?? false;
        $legalEvidence = $area !== PracticeArea::Unclassified || $matter !== null || $recognisedIntent;
        $intentSignal = $willCompleted || ($recommended && $legalEvidence && ($explicitRequest || $recognisedIntent));
        $hasIntent = $intentSignal && ($willCompleted || $explicitRequest || $intentConfident);
        $qualified = $hasIntent && $contactComplete && $classificationValid && $classificationConfident;

        $reason = match (true) {
            ! $classificationValid => 'classification_requires_review',
            ! $classificationConfident, $intentSignal && ! $hasIntent => 'qualification_confidence_too_low',
            ! $contactComplete => 'contact_details_incomplete',
            ! $hasIntent => 'insufficient_legal_intent',
            $source === 'staff' => 'staff_classification_confirmed',
            $willCompleted => 'will_workflow_completed',
            default => $consultation['reason'] ?? 'legal_intent_confirmed',
        };
        if ((! $classificationValid || ! $classificationConfident) && $source !== 'staff') {
            $area = PracticeArea::Unclassified;
            $matter = null;
        }
        $shouldPersist = $willCompleted || ($hasIntent && $contactComplete) || ($enquiry !== null && $intentSignal);

        return new QualificationResult($shouldPersist, $qualified, $area, $matter, $confidence, $source, ! $qualified, $reason, contactDetails: $contacts);
    }

    public function qualify(AiConversation $conversation, AiConversationMemory $memory, array $consultation): QualificationResult
    {
        return DB::transaction(function () use ($conversation, $memory, $consultation) {
            // Match the creation lock order. Hold the persisted memory stable
            // through decision, update and audit events, including concurrent calls.
            AiConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $memory = $conversation->memory()->whereKey($memory->id)->lockForUpdate()->firstOrFail();
            $enquiry = $conversation->enquiry()->lockForUpdate()->first();
            $workflow = $enquiry?->workflow;
            $willCompleted = $workflow?->status === 'completed' && $workflow->workflow_key === 'will_enquiry_v1';
            $decision = $this->decide($conversation, $memory, $consultation, $willCompleted, $enquiry);

            if ($workflow && $workflow->status !== 'completed') {
                return new QualificationResult(false, false, $decision->practiceArea, $decision->matterType, $decision->confidence, $decision->source, true, 'workflow_in_progress', $enquiry, $decision->contactDetails);
            }
            if (! $decision->shouldPersist) {
                return $decision;
            }
            $enquiry ??= $this->creation->getOrCreate($conversation->id, [
                'user_id' => $conversation->user_id,
                'practice_area' => $decision->practiceArea->value,
                'workflow_key' => 'victoria_conversation',
                'status' => 'draft',
                'started_at' => now(),
            ]);

            return $this->apply($enquiry, $conversation, $memory, $decision);
        }, attempts: 3);
    }

    /** Invoked within submitAnswer's existing locked transaction; never creates an enquiry. */
    public function completeWill(Enquiry $enquiry): void
    {
        $conversation = $enquiry->conversation;
        $memory = $conversation?->memory()->first();
        if ($conversation) {
            $decision = $this->decide($conversation, $memory, willCompleted: true, enquiry: $enquiry);
        } else {
            $contacts = $this->contacts(null, null, $enquiry);
            $complete = $this->hasContact(...array_values($contacts));
            $staff = $enquiry->classification_source === 'staff';
            $area = $staff ? PracticeArea::normalise($enquiry->practice_area) : PracticeArea::Wills;
            $matter = $staff ? $enquiry->matter_type : 'making_a_will';
            $valid = $area !== PracticeArea::Unclassified && filled($matter);
            $qualified = $complete && $valid;
            $reason = ! $valid ? 'classification_requires_review' : ($complete ? 'will_workflow_completed' : 'contact_details_incomplete');
            $decision = new QualificationResult(true, $qualified, $area, $matter, $staff ? (float) ($enquiry->classification_confidence ?? 1) : 1, $staff ? 'staff' : 'workflow', ! $qualified, $reason, contactDetails: $contacts);
        }
        $this->apply($enquiry, $conversation, $memory, $decision);
    }

    private function apply(Enquiry $enquiry, ?AiConversation $conversation, ?AiConversationMemory $memory, QualificationResult $decision): QualificationResult
    {
        $previousArea = $enquiry->practice_area;
        $previousReview = $enquiry->requires_review;
        $previousReason = $enquiry->handoff_reason;
        $classification = $enquiry->classification_source === 'staff' ? [] : [
            'practice_area' => $decision->practiceArea->value,
            'matter_type' => $decision->matterType,
            'classification_confidence' => $decision->confidence,
            'classification_source' => $decision->source,
        ];
        $attributes = [
            ...$classification,
            ...$decision->contactDetails,
            'office' => $conversation?->metadata['office'] ?? $enquiry->office,
            'recommended_service' => $conversation?->metadata['service'] ?? $enquiry->recommended_service,
            'requires_review' => $decision->requiresReview,
            'handoff_reason' => $decision->reason,
            'last_activity_at' => now(),
        ];
        if ($memory?->summary !== null) {
            $attributes['summary'] = ['text' => $memory->summary];
        }
        if ($decision->qualified) {
            $attributes['qualified_at'] = $enquiry->qualified_at ?? now();
            $attributes['submitted_at'] = $enquiry->submitted_at ?? now();
            if ($enquiry->status === 'draft') {
                $attributes['status'] = 'submitted';
            }
        }
        $enquiry->update($attributes);

        if ($previousArea === PracticeArea::Unclassified->value && $enquiry->practice_area !== PracticeArea::Unclassified->value) {
            $this->events->operational($enquiry, EnquiryEventService::ENQUIRY_CLASSIFIED);
        }
        $reviewEvent = $decision->reason === 'classification_requires_review'
            ? EnquiryEventService::CLASSIFICATION_REQUIRES_REVIEW : EnquiryEventService::ENQUIRY_QUALIFICATION_REQUIRES_REVIEW;
        if ($decision->requiresReview && ($previousReview !== true || $previousReason !== $decision->reason || ! $enquiry->events()->where('event_type', $reviewEvent)->exists())) {
            $this->events->operational($enquiry, $reviewEvent);
        }
        if ($decision->qualified) {
            foreach ([EnquiryEventService::ENQUIRY_QUALIFIED, EnquiryEventService::ENQUIRY_SUBMITTED, EnquiryEventService::ENQUIRY_READY_FOR_ASSIGNMENT] as $type) {
                if ($type === EnquiryEventService::ENQUIRY_READY_FOR_ASSIGNMENT && $enquiry->assigned_staff_id !== null) {
                    continue;
                }
                if (! $enquiry->events()->where('event_type', $type)->exists()) {
                    $this->events->operational($enquiry, $type);
                }
            }
        }

        return new QualificationResult(true, $decision->qualified, $decision->practiceArea, $decision->matterType, $decision->confidence, $decision->source, $decision->requiresReview, $decision->reason, $enquiry->fresh(), $decision->contactDetails);
    }

    /** Account details win; retain collected operational details before AI-derived fallbacks. */
    private function contacts(?AiConversation $conversation, ?AiConversationMemory $memory, ?Enquiry $enquiry): array
    {
        $user = $conversation?->user()->first() ?? $enquiry?->user()->first();
        $entities = $memory?->entities ?? [];
        $metadata = $conversation?->metadata ?? [];
        $pick = function (array $values, callable $valid): ?string {
            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '' && $valid(trim($value))) {
                    return trim($value);
                }
            }

            return null;
        };
        $derived = function (array $keys) use ($entities, $metadata): array {
            $values = [];
            foreach ([$entities, $metadata] as $data) {
                foreach (['', 'contact.', 'client.', 'contact_details.'] as $prefix) {
                    foreach ($keys as $key) {
                        $values[] = data_get($data, $prefix.$key);
                    }
                }
            }

            return $values;
        };

        return [
            'client_name' => $pick([$user?->name, $enquiry?->client_name, ...$derived(['client_name', 'full_name', 'name'])], fn ($value) => true),
            'client_email' => $pick([$user?->email, $enquiry?->client_email, ...$derived(['client_email', 'email'])], fn ($value) => filter_var($value, FILTER_VALIDATE_EMAIL) !== false),
            'client_phone' => $pick([$user?->contact_number, $enquiry?->client_phone, ...$derived(['client_phone', 'contact_number', 'phone', 'mobile'])], $this->validPhone(...)),
        ];
    }

    private function hasContact(?string $name, ?string $email, ?string $phone): bool
    {
        return filled($name) && (filter_var($email, FILTER_VALIDATE_EMAIL) !== false || $this->validPhone($phone ?? ''));
    }

    private function validPhone(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) >= 7 && strlen($digits) <= 15;
    }

    private function matterType(?string $value): ?string
    {
        $key = PracticeArea::key($value);
        foreach (config('enquiries.matter_types') as $canonical => $definition) {
            if ($key === $canonical || in_array($key, $definition['aliases'], true)) {
                return $canonical;
            }
        }

        return null;
    }
}
