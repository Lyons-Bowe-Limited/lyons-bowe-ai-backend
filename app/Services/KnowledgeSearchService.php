<?php

namespace App\Services;

use App\Models\KnowledgeDocument;

class KnowledgeSearchService
{
    public function search(string $message, int $limit = 5)
    {
        return KnowledgeDocument::query()
            ->where('status', 'published')
            ->where('visibility', 'ai_only')
            ->where(function ($query) use ($message) {
                $query->where('title', 'like', "%{$message}%")
                    ->orWhere('summary', 'like', "%{$message}%")
                    ->orWhere('content', 'like', "%{$message}%");
            })
            ->limit($limit)
            ->get();
    }
}