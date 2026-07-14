<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageSource;
use App\Services\BookingLinkService;
use App\Services\ClaudeService;
use App\Services\ConsultationRecommendationService;
use App\Services\ConversationContextService;
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
        BookingLinkService $bookingLinks
    ): JsonResponse {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
            'conversation_id' => ['nullable', 'uuid'],
            'office' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'string', 'max:150'],
        ]);

        $conversation = $this->getOrCreateConversation(
            $request,
            $validated
        );

        $isFirstAssistantMessage = ! $conversation
            ->messages()
            ->where('role', 'assistant')
            ->exists();

        $userMessage = AiMessage::create([
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
         * Update the persistent conversation memory.
         */
        $memory = $conversationContext->refresh(
            $conversation,
            $claude
        );

        $practiceArea = $memory->practice_area;

        /*
         * Determine whether a consultation should be presented.
         */
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
         * Once the conversation has a known practice area, remove
         * documents from unrelated practice areas.
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

        $reply = $claude->chat(
            message: $validated['message'],
            knowledgeContext: $knowledgeContext,
            conversationContext: $memoryPromptContext,
            history: $history,
            isFirstAssistantMessage: $isFirstAssistantMessage,
            bookingAvailable: $bookingLink !== null
        );

        $assistantMessage = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'metadata' => [
                'model' => config('services.anthropic.model'),
                'source_count' => $documents->count(),
                'practice_area' => $practiceArea,
                'matter_type' => $memory->matter_type,
                'intent' => $memory->intent,
                'conversation_stage' => $memory->conversation_stage,
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
                'booking_trigger_type' => $bookingLink?->trigger_type,
            ],
        ]);

        foreach ($documents as $document) {
            AiMessageSource::create([
                'ai_message_id' => $assistantMessage->id,
                'knowledge_document_id' => $document->id,
            ]);
        }

        $memory = $conversationContext->recordConsultationResult(
            $memory,
            $consultation,
            $bookingLink !== null
        );

        $conversationMetadata = $conversation->metadata ?? [];

        $conversationMetadata['practice_area'] = $memory->practice_area;
        $conversationMetadata['matter_type'] = $memory->matter_type;
        $conversationMetadata['intent'] = $memory->intent;
        $conversationMetadata['conversation_stage'] = (
            $memory->conversation_stage
        );

        if (! empty($validated['office'])) {
            $conversationMetadata['office'] = $validated['office'];
        }

        if (! empty($validated['service'])) {
            $conversationMetadata['service'] = $validated['service'];
        }

        $conversation->update([
            'metadata' => $conversationMetadata,
        ]);

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'reply' => $reply,
            'practice_area' => $memory->practice_area,

            'conversation_context' => [
                'matter_type' => $memory->matter_type,
                'intent' => $memory->intent,
                'conversation_stage' => $memory->conversation_stage,
                'summary' => $memory->summary,
                'entities' => $memory->entities,
            ],

            'sources' => $documents
                ->map(fn ($document) => [
                    'title' => $document->title,
                    'slug' => $document->slug,
                    'practice_area' => $document->practice_area,
                ])
                ->values(),

            'consultation' => $consultation,

            'booking_cta' => $bookingLink
                ? [
                    'label' => 'Book a consultation',
                    'url' => $bookingLink->booking_url,
                    'name' => $bookingLink->name,
                    'practice_area' => $bookingLink->practice_area,
                    'office' => $bookingLink->office,
                    'service' => $bookingLink->service,
                    'trigger_type' => $bookingLink->trigger_type,
                ]
                : null,
        ]);
    }

    private function getOrCreateConversation(
        Request $request,
        array $validated
    ): AiConversation {
        if (! empty($validated['conversation_id'])) {
            return AiConversation::query()
                ->where('uuid', $validated['conversation_id'])
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
        }

        return AiConversation::create([
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