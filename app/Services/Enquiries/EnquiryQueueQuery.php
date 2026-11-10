<?php

namespace App\Services\Enquiries;

use App\Enums\EnquiryStatus;
use Illuminate\Database\Eloquent\Builder;

class EnquiryQueueQuery
{
    /** Filters only narrow the caller's base query; they never replace its scope. */
    public function filter(Builder $query, array $filters, bool $sort = true): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['search']))).'%';
            $query->where(function ($query) use ($term) {
                foreach (['reference', 'client_name', 'client_email', 'client_phone'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        foreach (['status', 'practice_area', 'matter_type', 'assigned_staff_id', 'office'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (array_key_exists('requires_review', $filters)) {
            $query->where('requires_review', (bool) $filters['requires_review']);
        }
        if (array_key_exists('unassigned', $filters)) {
            $filters['unassigned'] ? $query->whereNull('assigned_staff_id') : $query->whereNotNull('assigned_staff_id');
        }

        return $sort ? $query->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')->orderBy('id') : $query;
    }

    public function sortMyWork(Builder $query, array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'created_at';
        if ($sort === 'priority') {
            // Fixed SQL, never a client-supplied expression. Unknown/NULL is last.
            return $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END ASC")
                ->orderByDesc('last_activity_at')->orderByDesc('id');
        }
        $column = match ($sort) {
            'created_at' => 'created_at',
            'last_activity_at' => 'last_activity_at',
            'submitted_at' => 'submitted_at',
            default => throw new \InvalidArgumentException('Unsupported My Work sort.'),
        };

        return $query->orderBy($column, $filters['direction'] ?? 'desc')->orderBy('id');
    }

    public function counts(Builder $query, bool $includeDraft = false): array
    {
        $counts = $query->selectRaw('COUNT(*) AS all_count,
            COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS submitted,
            COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS draft_count,
            COALESCE(SUM(CASE WHEN assigned_staff_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned,
            COALESCE(SUM(CASE WHEN assigned_staff_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS assigned,
            COALESCE(SUM(CASE WHEN requires_review THEN 1 ELSE 0 END), 0) AS review_count', [EnquiryStatus::Submitted->value, EnquiryStatus::Draft->value])->first();

        return [
            'all' => (int) $counts->all_count, 'submitted' => (int) $counts->submitted,
            ...($includeDraft ? ['draft' => (int) $counts->draft_count] : []),
            'unassigned' => (int) $counts->unassigned, 'assigned' => (int) $counts->assigned, 'requires_review' => (int) $counts->review_count,
        ];
    }
}
