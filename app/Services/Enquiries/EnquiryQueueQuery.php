<?php

namespace App\Services\Enquiries;

use App\Enums\EnquiryStatus;
use Illuminate\Database\Eloquent\Builder;

class EnquiryQueueQuery
{
    /** Filters only narrow the caller's base query; they never replace its scope. */
    public function filter(Builder $query, array $filters): Builder
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

        return $query->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')->orderBy('id');
    }

    public function counts(Builder $query): array
    {
        $counts = $query->selectRaw('COUNT(*) AS all_count,
            COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS submitted,
            COALESCE(SUM(CASE WHEN assigned_staff_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned,
            COALESCE(SUM(CASE WHEN assigned_staff_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS assigned,
            COALESCE(SUM(CASE WHEN requires_review THEN 1 ELSE 0 END), 0) AS review_count', [EnquiryStatus::Submitted->value])->first();

        return [
            'all' => (int) $counts->all_count, 'submitted' => (int) $counts->submitted,
            'unassigned' => (int) $counts->unassigned, 'assigned' => (int) $counts->assigned, 'requires_review' => (int) $counts->review_count,
        ];
    }
}
