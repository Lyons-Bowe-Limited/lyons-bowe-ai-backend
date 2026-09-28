<?php

namespace App\Services;

use App\Models\AiConversation;
use App\Models\AiConversationMemory;
use Illuminate\Support\Str;

class ConversationContextService
{
    private const PRACTICE_AREAS = [
        'property_law',
        'family_law',
        'wills_and_probate',
        'general',
    ];

    private const CONVERSATION_STAGES = [
        'information_gathering',
        'guidance',
        'consultation_ready',
        'booking_presented',
        'completed',
    ];

    public function getOrCreate(
        AiConversation $conversation
    ): AiConversationMemory {
        return $conversation->memory()->firstOrCreate(
            [],
            [
                'uuid' => (string) Str::uuid(),
                'conversation_stage' => 'information_gathering',
                'entities' => [],
                'practice_area_confidence' => 0,
                'intent_confidence' => 0,
                'consultation_recommended' => false,
                'booking_presented' => false,
                'metadata' => [],
            ]
        );
    }

    public function refresh(
        AiConversation $conversation,
        ClaudeService $claude
    ): AiConversationMemory {
        $memory = $this->getOrCreate($conversation);

        $messages = $this->recentHistory(
            $conversation,
            null,
            12
        );

        $currentMemory = [
            'practice_area' => $memory->practice_area,
            'matter_type' => $memory->matter_type,
            'conversation_stage' => $memory->conversation_stage,
            'intent' => $memory->intent,
            'summary' => $memory->summary,
            'entities' => $memory->entities ?? [],
            'practice_area_confidence' => $memory->practice_area_confidence,
            'intent_confidence' => $memory->intent_confidence,
        ];

        $updatedContext = $claude->extractConversationContext(
            $currentMemory,
            $messages
        );

        if ($updatedContext === []) {
            return $memory;
        }

        $practiceArea = $this->validPracticeArea(
            $updatedContext['practice_area'] ?? null,
            $memory->practice_area
        );

        $conversationStage = $this->validConversationStage(
            $updatedContext['conversation_stage'] ?? null,
            $memory->conversation_stage
        );

        $newEntities = is_array($updatedContext['entities'] ?? null)
            ? $updatedContext['entities']
            : [];

        $mergedEntities = array_replace_recursive(
            $memory->entities ?? [],
            $newEntities
        );

        $memory->update([
            'practice_area' => $practiceArea,
            'matter_type' => $this->cleanNullableString(
                $updatedContext['matter_type'] ?? null,
                $memory->matter_type
            ),
            'conversation_stage' => $conversationStage,
            'intent' => $this->cleanNullableString(
                $updatedContext['intent'] ?? null,
                $memory->intent
            ),
            'summary' => $this->cleanNullableString(
                $updatedContext['summary'] ?? null,
                $memory->summary
            ),
            'entities' => $mergedEntities,
            'practice_area_confidence' => $this->normaliseConfidence(
                $updatedContext['practice_area_confidence'] ?? null,
                $memory->practice_area_confidence
            ),
            'intent_confidence' => $this->normaliseConfidence(
                $updatedContext['intent_confidence'] ?? null,
                $memory->intent_confidence
            ),
        ]);

        return $memory->fresh();
    }

    public function recentHistory(
        AiConversation $conversation,
        ?int $excludeMessageId = null,
        int $limit = 10
    ): array {
        $query = $conversation->messages()
            ->whereIn('role', ['user', 'assistant']);

        if ($excludeMessageId !== null) {
            $query->where('id', '!=', $excludeMessageId);
        }

        return $query
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();
    }

    public function buildPromptContext(
        AiConversationMemory $memory
    ): string {
        $entities = $memory->entities ?? [];

        $entitiesJson = $entities !== []
            ? json_encode(
                $entities,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
            : '{}';

        return <<<CONTEXT
Active practice area: {$memory->practice_area}
Matter type: {$memory->matter_type}
Conversation stage: {$memory->conversation_stage}
Current user intent: {$memory->intent}

Conversation summary:
{$memory->summary}

Known facts and entities:
{$entitiesJson}

Use this context to maintain continuity. Do not ask for information already recorded here unless clarification is genuinely necessary.
CONTEXT;
    }

    public function recordConsultationResult(
        AiConversationMemory $memory,
        array $consultation,
        bool $bookingPresented
    ): AiConversationMemory {
        $stage = $memory->conversation_stage;

        if ($bookingPresented) {
            $stage = 'booking_presented';
        } elseif (($consultation['recommend'] ?? false) === true) {
            $stage = 'consultation_ready';
        }

        $metadata = $memory->metadata ?? [];

        $metadata['last_consultation_result'] = [
            'recommend' => $consultation['recommend'] ?? false,
            'reason' => $consultation['reason'] ?? null,
            'trigger_type' => $consultation['trigger_type'] ?? null,
            'confidence' => $consultation['confidence'] ?? null,
        ];

        $memory->update([
            'conversation_stage' => $stage,
            'consultation_recommended' => (
                $consultation['recommend'] ?? false
            ),
            'booking_presented' => $bookingPresented,
            'metadata' => $metadata,
        ]);

        return $memory->fresh();
    }

    private function validPracticeArea(
        mixed $value,
        ?string $fallback
    ): ?string {
        if (
            is_string($value)
            && in_array($value, self::PRACTICE_AREAS, true)
        ) {
            return $value;
        }

        return $fallback;
    }

    private function validConversationStage(
        mixed $value,
        ?string $fallback
    ): string {
        if (
            is_string($value)
            && in_array($value, self::CONVERSATION_STAGES, true)
        ) {
            return $value;
        }

        return $fallback ?: 'information_gathering';
    }

    private function cleanNullableString(
        mixed $value,
        ?string $fallback
    ): ?string {
        if (! is_string($value)) {
            return $fallback;
        }

        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }

    private function normaliseConfidence(
        mixed $value,
        float|int|null $fallback
    ): float {
        if (! is_numeric($value)) {
            return (float) ($fallback ?? 0);
        }

        $confidence = (float) $value;

        if ($confidence > 1) {
            $confidence = $confidence / 100;
        }

        return max(0, min(1, $confidence));
    }
}