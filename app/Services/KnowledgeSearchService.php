<?php

namespace App\Services;

use App\Models\KnowledgeDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class KnowledgeSearchService
{
    public function search(string $message, int $limit = 5): Collection
    {
        $messageLower = Str::lower($message);

        $practiceArea = $this->detectPracticeArea($messageLower);

        $terms = $this->extractSearchTerms($messageLower);

        return KnowledgeDocument::query()
            ->where('status', 'published')
            ->where('visibility', 'ai_only')
            ->when($practiceArea, function ($query) use ($practiceArea) {
                $query->where('practice_area', $practiceArea);
            })
            ->where(function ($query) use ($terms, $messageLower, $practiceArea) {
                foreach ($terms as $term) {
                    $query->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%")
                        ->orWhere('content', 'like', "%{$term}%")
                        ->orWhere('category', 'like', "%{$term}%");
                }

                if ($practiceArea) {
                    $query->orWhere('practice_area', $practiceArea);
                }
            })
            ->limit($limit)
            ->get();
    }

    private function detectPracticeArea(string $message): ?string
    {
        $propertyTerms = [
            'property',
            'house',
            'home',
            'buy',
            'buying',
            'sell',
            'selling',
            'conveyancing',
            'mortgage',
            'remortgage',
            'auction',
            'new build',
            'transfer of equity',
            'completion',
            'exchange',
        ];

        $familyTerms = [
            'family',
            'divorce',
            'separation',
            'child',
            'children',
            'custody',
            'contact',
            'arrangements',
            'financial settlement',
            'spousal',
            'maintenance',
            'cohabitation',
            'prenup',
            'postnup',
            'domestic abuse',
            'civil partnership',
        ];

        $probateTerms = [
            'will',
            'wills',
            'probate',
            'estate',
            'inheritance',
            'executor',
            'executors',
            'lasting power of attorney',
            'lpa',
            'trust',
            'deceased',
        ];

        if ($this->containsAny($message, $familyTerms)) {
            return 'family_law';
        }

        if ($this->containsAny($message, $probateTerms)) {
            return 'wills_and_probate';
        }

        if ($this->containsAny($message, $propertyTerms)) {
            return 'property_law';
        }

        return null;
    }

    private function extractSearchTerms(string $message): Collection
    {
        return collect(preg_split('/\s+/', $message))
            ->map(fn ($term) => trim($term, ".,?!'\"()[]{}"))
            ->filter(fn ($term) => strlen($term) >= 3)
            ->reject(fn ($term) => in_array($term, [
                'the',
                'and',
                'for',
                'with',
                'that',
                'this',
                'what',
                'how',
                'can',
                'you',
                'your',
                'when',
                'where',
                'why',
                'does',
                'about',
                'into',
                'need',
                'help',
            ]))
            ->values();
    }

    private function containsAny(string $message, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($message, $term)) {
                return true;
            }
        }

        return false;
    }
}