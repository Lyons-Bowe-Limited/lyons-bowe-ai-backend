<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageSource;
use App\Models\Enquiry;
use App\Services\BookingLinkService;
use App\Services\ClaudeService;
use App\Services\ConsultationRecommendationService;
use App\Services\ConversationContextService;
use App\Services\Enquiries\EnquiryWorkflowService;
use App\Services\Enquiries\WorkflowAnswerInterpreter;
use App\Services\Enquiries\WorkflowDefinitionService;
use App\Services\Enquiries\WorkflowIntentDetector;
use App\Services\Enquiries\WorkflowReplyService;
use App\Services\KnowledgeSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiPlaygroundController extends Controller
{
    public function chat(
        Request $request,
        ClaudeService $claude,
        KnowledgeSearchService $knowledge,
        ConversationContextService $conversationContext,
        ConsultationRecommendationService $consultationRecommendation,
        BookingLinkService $bookingLinks,
        EnquiryWorkflowService $enquiryWorkflow,
        WorkflowDefinitionService $workflowDefinitions,
        WorkflowIntentDetector $workflowIntentDetector,
        WorkflowAnswerInterpreter $workflowAnswerInterpreter,
        WorkflowReplyService $workflowReplies
    ): JsonResponse {
        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:3000',
            ],

            'conversation_id' => [
                'nullable',
                'uuid',
            ],

            'office' => [
                'nullable',
                'string',
                'max:100',
            ],

            'service' => [
                'nullable',
                'string',
                'max:150',
            ],
        ]);

        $conversation = $this->getOrCreateConversation(
            $request,
            $validated
        );

        $isFirstAssistantMessage = ! $conversation
            ->messages()
            ->where('role', 'assistant')
            ->exists();

        $userMessage = AiMessage::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $validated['message'],
            'metadata' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'office' => $validated['office'] ?? null,
                'service' => $validated['service'] ?? null,
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Continue an active enquiry workflow
        |--------------------------------------------------------------------------
        */

        $activeEnquiry = $this->findActiveEnquiry(
            userId: $request->user()->id,
            conversationId: $conversation->id
        );

        if ($activeEnquiry) {
            $workflow = $activeEnquiry->workflow;

            if (
                ! $workflow
                || ! $workflow->current_step_key
            ) {
                return response()->json([
                    'message' => 'The active enquiry workflow could not be loaded.',
                ], 422);
            }

            $currentStepKey = $workflow->current_step_key;

            $step = $workflowDefinitions->getStep(
                $workflow->workflow_key,
                $currentStepKey
            );

            $interpretation = $workflowAnswerInterpreter
                ->interpret(
                    $step,
                    $validated['message']
                );

            /*
             * The user answer could not be safely interpreted.
             * Keep the workflow on the same step.
             */
            if (! $interpretation['success']) {
                $reply = $workflowReplies->clarification(
                    $step,
                    $interpretation['clarification']
                );

                $this->storeWorkflowAssistantMessage(
                    conversation: $conversation,
                    reply: $reply,
                    enquiry: $activeEnquiry,
                    interactionType: 'workflow_clarification',
                    stepKey: $currentStepKey
                );

                return $this->workflowResponse(
                    conversation: $conversation,
                    reply: $reply,
                    enquiry: $activeEnquiry,
                    interactionType: 'workflow_clarification'
                );
            }

            /*
             * Save the interpreted answer and move to the next
             * deterministic workflow step.
             */
            $result = $enquiryWorkflow->submitAnswer(
                enquiry: $activeEnquiry,
                stepKey: $currentStepKey,
                answer: $interpretation['answer'],
                performedBy: $request->user()->id
            );

            if ($result->completed) {
                $reply = $workflowReplies->completed(
                    $result->enquiry->reference
                );

                $interactionType = 'workflow_completed';
            } else {
                if (! $result->currentStep) {
                    return response()->json([
                        'message' => 'The next workflow step could not be loaded.',
                    ], 422);
                }

                $reply = $workflowReplies->question(
                    previousStepKey: $currentStepKey,
                    previousAnswer: $interpretation['answer'],
                    nextStep: $result->currentStep
                );

                $interactionType = 'workflow_question';
            }

            $this->storeWorkflowAssistantMessage(
                conversation: $conversation,
                reply: $reply,
                enquiry: $result->enquiry,
                interactionType: $interactionType,
                stepKey: $result->currentStepKey
            );

            return $this->workflowResponse(
                conversation: $conversation,
                reply: $reply,
                enquiry: $result->enquiry,
                interactionType: $interactionType,
                result: $result->toArray()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Detect whether a new Will enquiry should begin
        |--------------------------------------------------------------------------
        */

        $workflowIntent = $workflowIntentDetector->detect(
            $validated['message']
        );

        if (
            $workflowIntent
            && $workflowIntent['intent'] === 'start_enquiry'
        ) {
            $result = $enquiryWorkflow->start(
                practiceArea: $workflowIntent['practice_area'],
                user: $request->user(),
                conversationId: $conversation->id,
                workflowKey: $workflowIntent['workflow_key'],
                priority: 'normal'
            );

            if (! $result->currentStep) {
                return response()->json([
                    'message' => 'The Will enquiry could not be started.',
                ], 422);
            }

            $reply = $workflowReplies->started(
                $result->currentStep
            );

            $this->storeWorkflowAssistantMessage(
                conversation: $conversation,
                reply: $reply,
                enquiry: $result->enquiry,
                interactionType: 'workflow_started',
                stepKey: $result->currentStepKey
            );

            return $this->workflowResponse(
                conversation: $conversation,
                reply: $reply,
                enquiry: $result->enquiry,
                interactionType: 'workflow_started',
                result: $result->toArray()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normal Victoria conversation
        |--------------------------------------------------------------------------
        */

        $completedEnquiry = Enquiry::query()
            ->where('user_id', $request->user()->id)
            ->where('conversation_id', $conversation->id)
            ->where('status', 'completed')
            ->with('workflow')
            ->latest('completed_at')
            ->first();

        $memory = $conversationContext->refresh(
            $conversation,
            $claude
        );

        $practiceArea = $memory->practice_area;

        $consultation = $consultationRecommendation->evaluate(
            $validated['message'],
            $conversation,
            $practiceArea
        );

        $bookingLink = null;

        if (($consultation['recommend'] ?? false) === true) {
            $bookingLink = $bookingLinks->find(
                $practiceArea,
                $validated['office'] ?? null,
                $validated['service'] ?? null
            );
        }

        /*
         * Search the authorised Knowledge Bank.
         */
        $documents = $knowledge->search(
            $validated['message']
        );

        /*
         * Once a practice area is known, remove documents from
         * unrelated practice areas.
         */
        if ($practiceArea) {
            $documents = $documents
                ->filter(fn ($document) => in_array(
                    $document->practice_area,
                    [$practiceArea, 'general'],
                    true
                ))
                ->values();
        }

        $knowledgeContext = $documents
            ->map(function ($document) {
                return trim("
                    Title: {$document->title}
                    Practice Area: {$document->practice_area}
                    Category: {$document->category}
                    Summary: {$document->summary}
                    Content: {$document->content}
                ");
            })
            ->implode("\n\n---\n\n");

        $memoryPromptContext = $conversationContext
            ->buildPromptContext($memory);

        /*
         * Exclude the latest user message because it is supplied
         * separately as the latest message to Claude.
         */
        $history = $conversationContext->recentHistory(
            $conversation,
            $userMessage->id,
            10
        );

        /*
         * ClaudeService and its approved prompt remain unchanged.
         */
        $reply = $claude->chat(
            message: $validated['message'],
            knowledgeContext: $knowledgeContext,
            conversationContext: $memoryPromptContext,
            history: $history,
            isFirstAssistantMessage: $isFirstAssistantMessage,
            bookingAvailable: $bookingLink !== null
        );

        $assistantMessage = AiMessage::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'metadata' => [
                'model' => config('services.anthropic.model'),
                'source_count' => $documents->count(),
                'practice_area' => $practiceArea,
                'matter_type' => $memory->matter_type,
                'intent' => $memory->intent,
                'conversation_stage' =>
                    $memory->conversation_stage,

                'consultation_recommended' => (
                    $consultation['recommend'] ?? false
                ),

                'consultation_reason' => (
                    $consultation['reason'] ?? null
                ),

                'consultation_trigger_type' => (
                    $consultation['trigger_type'] ?? null
                ),

                'consultation_confidence' => (
                    $consultation['confidence'] ?? null
                ),

                'booking_link_id' => $bookingLink?->id,
                'booking_link_uuid' => $bookingLink?->uuid,
                'booking_trigger_type' =>
                    $bookingLink?->trigger_type,
            ],
        ]);

        foreach ($documents as $document) {
            AiMessageSource::query()->create([
                'ai_message_id' => $assistantMessage->id,
                'knowledge_document_id' => $document->id,
            ]);
        }

        $memory = $conversationContext
            ->recordConsultationResult(
                $memory,
                $consultation,
                $bookingLink !== null
            );

        $conversationMetadata = $conversation->metadata ?? [];

        $conversationMetadata['practice_area'] =
            $memory->practice_area;

        $conversationMetadata['matter_type'] =
            $memory->matter_type;

        $conversationMetadata['intent'] =
            $memory->intent;

        $conversationMetadata['conversation_stage'] =
            $memory->conversation_stage;

        if (! empty($validated['office'])) {
            $conversationMetadata['office'] =
                $validated['office'];
        }

        if (! empty($validated['service'])) {
            $conversationMetadata['service'] =
                $validated['service'];
        }

        $conversation->update([
            'metadata' => $conversationMetadata,
        ]);

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'reply' => $reply,
            'practice_area' => $memory->practice_area,

            'interaction' => [
                'type' => 'chat',
                'enquiry_id' => null,
                'enquiry_reference' => null,
                'workflow_active' => false,
                'workflow_completed' => false,
                'workflow_key' => null,
                'current_step_key' => null,
                'progress' => null,
            ],

            'conversation_context' => [
                'matter_type' => $memory->matter_type,
                'intent' => $memory->intent,
                'conversation_stage' =>
                    $memory->conversation_stage,
                'summary' => $memory->summary,
                'entities' => $memory->entities,
            ],

            'sources' => $documents
                ->map(fn ($document) => [
                    'title' => $document->title,
                    'slug' => $document->slug,
                    'practice_area' =>
                        $document->practice_area,
                ])
                ->values(),

            'consultation' => $consultation,

            'booking_cta' => $bookingLink
                ? [
                    'label' => 'Book a consultation',
                    'url' => $bookingLink->booking_url,
                    'name' => $bookingLink->name,
                    'practice_area' =>
                        $bookingLink->practice_area,
                    'office' => $bookingLink->office,
                    'service' => $bookingLink->service,
                    'trigger_type' =>
                        $bookingLink->trigger_type,
                ]
                : null,
        ]);
    }

    private function findActiveEnquiry(
        int $userId,
        int $conversationId
    ): ?Enquiry {
        return Enquiry::query()
            ->where('user_id', $userId)
            ->where('conversation_id', $conversationId)
            ->where('status', 'in_progress')
            ->whereHas(
                'workflow',
                fn ($query) => $query->where(
                    'status',
                    'in_progress'
                )
            )
            ->with('workflow')
            ->latest('created_at')
            ->first();
    }

    private function storeWorkflowAssistantMessage(
        AiConversation $conversation,
        string $reply,
        Enquiry $enquiry,
        string $interactionType,
        ?string $stepKey
    ): AiMessage {
        return AiMessage::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'metadata' => [
                'interaction_type' => $interactionType,
                'enquiry_id' => $enquiry->id,
                'enquiry_reference' => $enquiry->reference,
                'workflow_key' => $enquiry->workflow_key,
                'workflow_step_key' => $stepKey,
                'workflow_progress' =>
                    $enquiry->completion_percentage,
            ],
        ]);
    }

    private function workflowResponse(
        AiConversation $conversation,
        string $reply,
        Enquiry $enquiry,
        string $interactionType,
        ?array $result = null
    ): JsonResponse {
        $enquiry->loadMissing('workflow');

        $completed = $enquiry->status === 'completed'
            || $enquiry->workflow?->status === 'completed';

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'reply' => $reply,
            'practice_area' => $enquiry->practice_area,

            'interaction' => [
                'type' => $interactionType,
                'enquiry_id' => $enquiry->id,
                'enquiry_reference' =>
                    $enquiry->reference,
                'workflow_active' => ! $completed,
                'workflow_completed' => $completed,
                'workflow_key' => $enquiry->workflow_key,
                'current_step_key' =>
                    $enquiry->workflow?->current_step_key,
                'progress' =>
                    $enquiry->completion_percentage,
            ],

            'workflow' => $result,

            'conversation_context' => null,
            'sources' => [],
            'consultation' => null,
            'booking_cta' => null,
        ]);
    }

    private function getOrCreateConversation(
        Request $request,
        array $validated
    ): AiConversation {
        if (! empty($validated['conversation_id'])) {
            return AiConversation::query()
                ->where(
                    'uuid',
                    $validated['conversation_id']
                )
                ->where(
                    'user_id',
                    $request->user()->id
                )
                ->firstOrFail();
        }

        return AiConversation::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $request->user()->id,
            'title' => Str::limit(
                $validated['message'],
                80
            ),
            'status' => 'active',
            'metadata' => [
                'started_from' => 'playground',
                'office' => $validated['office'] ?? null,
                'service' => $validated['service'] ?? null,
            ],
        ]);
    }
}