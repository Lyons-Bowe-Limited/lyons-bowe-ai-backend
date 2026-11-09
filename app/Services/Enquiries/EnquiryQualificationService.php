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

    public function decide(AiConversation $conversation, ?AiConversationMemory $memory, array $consultation = [], bool $willCompleted = false): QualificationResult
    {
        $user = $conversation->user;
        $contactComplete = $this->hasContact($user?->name, $user?->email, $user?->contact_number);
        $area = PracticeArea::normalise($memory?->practice_area);
        $matter = $this->matterType($memory?->matter_type);
        $confidence = max(0, min(1, (float) ($memory?->practice_area_confidence ?? 0)));
        $source = 'victoria';

        if ($willCompleted) {
            // Completion of the deterministic Will workflow is stronger evidence
            // than stale memory from before the questionnaire began.
            $area = PracticeArea::Wills;
            $matter = 'making_a_will';
            $confidence = 1;
            $source = 'workflow';
        }

        $explicitRequest = in_array($consultation['reason'] ?? null, config('enquiries.explicit_consultation_reasons'), true);
        $recognisedIntent = in_array(PracticeArea::key($memory?->intent), config('enquiries.legal_intents'), true)
            && (float) ($memory?->intent_confidence ?? 0) >= config('enquiries.intent_confidence_threshold');
        $legalEvidence = $area !== PracticeArea::Unclassified || $matter !== null || $recognisedIntent;
        $hasIntent = $willCompleted || (($consultation['recommend'] ?? false) && $legalEvidence && ($explicitRequest || $recognisedIntent));
        $shouldPersist = $willCompleted || ($hasIntent && $contactComplete);
        $classificationCertain = $area !== PracticeArea::Unclassified
            && $matter !== null
            && config("enquiries.matter_types.{$matter}.practice_area") === $area->value
            && $confidence >= config('enquiries.classification_confidence_threshold');

        if (! $classificationCertain) {
            $area = PracticeArea::Unclassified;
            $matter = null;
        }

        $qualified = $hasIntent && $contactComplete && $classificationCertain;
        $reason = ! $contactComplete ? 'missing_contact_details'
            : (! $hasIntent ? 'insufficient_legal_intent'
                : (! $classificationCertain ? 'classification_requires_review' : ($willCompleted ? 'will_workflow_completed' : ($consultation['reason'] ?? 'legal_intent_confirmed'))));

        return new QualificationResult($shouldPersist, $qualified, $area, $matter, $confidence, $source, ! $qualified, $reason);
    }

    public function qualify(AiConversation $conversation, AiConversationMemory $memory, array $consultation): QualificationResult
    {
        $decision = $this->decide($conversation, $memory, $consultation);
        if (! $decision->shouldPersist) {
            if ($decision->reason !== 'missing_contact_details') {
                return $decision;
            }

            // Missing contact must also revoke readiness on a previously qualified
            // record, without creating an enquiry for an incomplete conversation.
            return DB::transaction(function () use ($conversation, $memory, $decision) {
                $existing = Enquiry::query()->where('conversation_id', $conversation->id)->lockForUpdate()->first();
                if (! $existing || $existing->workflow()->where('status', '!=', 'completed')->exists()) {
                    return $decision;
                }

                return $this->apply($existing, $conversation, $memory, $decision);
            }, attempts: 3);
        }

        return DB::transaction(function () use ($conversation, $memory, $decision) {
            $enquiry = $this->creation->getOrCreate($conversation->id, [
                'user_id' => $conversation->user_id,
                'practice_area' => $decision->practiceArea->value,
                // Ordinary enquiries do not have a questionnaire workflow.
                'workflow_key' => 'victoria_conversation',
                'status' => 'draft',
                'started_at' => now(),
            ]);
            $enquiry = Enquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();

            // A Will journey owns its own completion transition.
            $workflow = $enquiry->workflow;
            if ($workflow && $workflow->status !== 'completed') {
                return new QualificationResult(false, false, $decision->practiceArea, $decision->matterType, $decision->confidence, $decision->source, true, 'workflow_in_progress', $enquiry);
            }

            if ($workflow?->status === 'completed' && $workflow->workflow_key === 'will_enquiry_v1') {
                $decision = $this->decide($conversation, $memory, willCompleted: true);
            }

            return $this->apply($enquiry, $conversation, $memory, $decision);
        }, attempts: 3);
    }

    /** Invoked within submitAnswer's existing locked transaction; never creates an enquiry. */
    public function completeWill(Enquiry $enquiry): void
    {
        $conversation = $enquiry->conversation;
        if ($conversation) {
            $this->apply($enquiry, $conversation, $conversation->memory, $this->decide($conversation, $conversation->memory, willCompleted: true));

            return;
        }

        // Direct workflow API also supports enquiries without conversations.
        $user = $enquiry->user;
        $complete = $this->hasContact($user?->name, $user?->email, $user?->contact_number);
        $decision = new QualificationResult(true, $complete, PracticeArea::Wills, 'making_a_will', 1, 'workflow', ! $complete, $complete ? 'will_workflow_completed' : 'missing_contact_details');
        $this->apply($enquiry, null, null, $decision);
    }

    private function apply(Enquiry $enquiry, ?AiConversation $conversation, ?AiConversationMemory $memory, QualificationResult $decision): QualificationResult
    {
        $user = $conversation?->user ?? $enquiry->user;
        $previousReview = $enquiry->requires_review;
        $classification = [
            'practice_area' => $decision->practiceArea->value,
            'matter_type' => $decision->matterType,
            'classification_confidence' => $decision->confidence,
            'classification_source' => $decision->source,
        ];
        // Staff corrections are authoritative; AI refreshes cannot replace them.
        if ($enquiry->classification_source === 'staff') {
            $area = PracticeArea::normalise($enquiry->practice_area);
            $matter = $this->matterType($enquiry->matter_type);
            $certain = $area !== PracticeArea::Unclassified && $matter !== null
                && config("enquiries.matter_types.{$matter}.practice_area") === $area->value;
            $qualified = $certain && $this->hasContact($user?->name, $user?->email, $user?->contact_number);
            $decision = new QualificationResult(true, $qualified, $area, $matter, (float) ($enquiry->classification_confidence ?? 1), 'staff', ! $qualified, $qualified ? 'staff_classification_confirmed' : ($certain ? 'missing_contact_details' : 'classification_requires_review'));
            $classification = [];
        }

        $attributes = [
            ...$classification,
            'client_name' => $user ? $user->name : $enquiry->client_name,
            'client_email' => $user ? $user->email : $enquiry->client_email,
            'client_phone' => $user ? $user->contact_number : $enquiry->client_phone,
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

        if ($decision->requiresReview && ($previousReview !== true || ! $enquiry->events()->where('event_type', EnquiryEventService::CLASSIFICATION_REQUIRES_REVIEW)->exists())) {
            $this->events->operational($enquiry, EnquiryEventService::CLASSIFICATION_REQUIRES_REVIEW);
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

        return new QualificationResult(true, $decision->qualified, $decision->practiceArea, $decision->matterType, $decision->confidence, $decision->source, $decision->requiresReview, $decision->reason, $enquiry->fresh());
    }

    private function hasContact(?string $name, ?string $email, ?string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone ?? '');

        return trim($name ?? '') !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) !== false || (strlen($digits) >= 7 && strlen($digits) <= 15));
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
