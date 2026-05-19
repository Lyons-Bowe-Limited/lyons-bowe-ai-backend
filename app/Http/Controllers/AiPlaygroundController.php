<?php

namespace App\Http\Controllers;

use App\Services\ClaudeService;
use Illuminate\Http\Request;
use App\Services\KnowledgeSearchService;

class AiPlaygroundController extends Controller
{
    public function chat(
        Request $request,
        ClaudeService $claude,
        KnowledgeSearchService $knowledge
    ) {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
        ]);
    
        $documents = $knowledge->search($validated['message']);
    
        $context = $documents->map(fn ($doc) => "
            Title: {$doc->title}
            Practice Area: {$doc->practice_area}
            Summary: {$doc->summary}
            Content: {$doc->content}
        ")->implode("\n---\n");
    
        return response()->json([
            'reply' => $claude->chat($validated['message'], $context),
            'sources' => $documents->map(fn ($doc) => [
                'title' => $doc->title,
                'slug' => $doc->slug,
                'practice_area' => $doc->practice_area,
            ]),
        ]);
    }
}