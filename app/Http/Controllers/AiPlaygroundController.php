<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageSource;
use App\Services\ClaudeService;
use App\Services\KnowledgeSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiPlaygroundController extends Controller
{
    public function chat(
        Request $request,
        ClaudeService $claude,
        KnowledgeSearchService $knowledge
    ) {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
            'conversation_id' => ['nullable', 'string'],
        ]);

        $conversation = $this->getOrCreateConversation($request, $validated);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $validated['message'],
            'metadata' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);

        $documents = $knowledge->search($validated['message']);

        $context = $documents->map(fn ($doc) => "
        Title: {$doc->title}
        Practice Area: {$doc->practice_area}
        Summary: {$doc->summary}
        Content: {$doc->content}
        ")->implode("\n---\n");

        $reply = $claude->chat($validated['message'], $context);

        $assistantMessage = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'metadata' => [
                'model' => config('services.anthropic.model'),
                'source_count' => $documents->count(),
            ],
        ]);

        foreach ($documents as $document) {
            AiMessageSource::create([
                'ai_message_id' => $assistantMessage->id,
                'knowledge_document_id' => $document->id,
            ]);
        }

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'reply' => $reply,
            'sources' => $documents->map(fn ($doc) => [
                'title' => $doc->title,
                'slug' => $doc->slug,
                'practice_area' => $doc->practice_area,
            ])->values(),
        ]);
    }

    private function getOrCreateConversation(Request $request, array $validated): AiConversation
    {
        if (! empty($validated['conversation_id'])) {
            $conversation = AiConversation::where('uuid', $validated['conversation_id'])->first();

            if ($conversation) {
                return $conversation;
            }
        }

        return AiConversation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $request->user()?->id,
            'title' => Str::limit($validated['message'], 80),
            'status' => 'active',
            'metadata' => [
                'started_from' => 'playground',
            ],
        ]);
    }
}