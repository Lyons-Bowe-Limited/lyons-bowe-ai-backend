<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use Illuminate\Http\Request;

class AiConversationController extends Controller
{
    public function index(Request $request)
    {
        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at');
            }])
            ->latest('updated_at')
            ->get()
            ->map(function ($conversation) {
                $userMessage = $conversation->messages->firstWhere('role', 'user');
                $assistantMessage = $conversation->messages->firstWhere('role', 'assistant');

                return [
                    'id' => $conversation->uuid,
                    'title' => $conversation->title,
                    'userMessage' => $userMessage?->content,
                    'assistantMessage' => $assistantMessage?->content,
                    'date' => $conversation->updated_at?->diffForHumans(),
                    'category' => $conversation->metadata['category'] ?? 'AI Chat',
                    'hasAttachment' => false,
                    'isStarred' => (bool) $conversation->is_starred,
                ];
            });

        return response()->json([
            'data' => $conversations,
        ]);
    }

    public function show(Request $request, string $uuid)
    {
        $conversation = AiConversation::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at');
            }])
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $conversation->uuid,
                'title' => $conversation->title,
                'category' => $conversation->metadata['category'] ?? 'AI Chat',
                'date' => $conversation->updated_at?->diffForHumans(),
                'isStarred' => false,
                'messages' => $conversation->messages
                    ->map(fn ($message) => [
                        'id' => (string) $message->id,
                        'role' => $message->role,
                        'content' => $message->content,
                        'created_at' => $message->created_at,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function starred(Request $request)
    {
        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('is_starred', true)
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at');
            }])
            ->latest('updated_at')
            ->get()
            ->map(function ($conversation) {
                $userMessage = $conversation->messages->firstWhere('role', 'user');
                $assistantMessage = $conversation->messages->firstWhere('role', 'assistant');

                return [
                    'id' => $conversation->uuid,
                    'title' => $conversation->title,
                    'userMessage' => $userMessage?->content,
                    'assistantMessage' => $assistantMessage?->content,
                    'date' => $conversation->updated_at?->diffForHumans(),
                    'category' => $conversation->metadata['category'] ?? 'AI Chat',
                    'hasAttachment' => false,
                    'isStarred' => (bool) $conversation->is_starred,
                ];
            });

        return response()->json([
            'data' => $conversations,
        ]);
    }

    public function updateStar(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'is_starred' => ['required', 'boolean'],
        ]);

        $conversation = AiConversation::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $conversation->update([
            'is_starred' => $validated['is_starred'],
        ]);

        return response()->json([
            'data' => [
                'id' => $conversation->uuid,
                'isStarred' => (bool) $conversation->is_starred,
            ],
        ]);
    }
}