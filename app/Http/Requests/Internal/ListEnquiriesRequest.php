<?php

namespace App\Http\Requests\Internal;

use App\Enums\EnquiryStatus;
use App\Enums\PracticeArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEnquiriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(EnquiryStatus::class)],
            'practice_area' => ['sometimes', Rule::enum(PracticeArea::class)],
            'matter_type' => ['sometimes', Rule::in(array_keys(config('enquiries.matter_types')))],
            'assigned_staff_id' => ['sometimes', 'integer', Rule::exists('staff_users', 'id'), 'prohibits:unassigned'],
            'unassigned' => ['sometimes', 'boolean'],
            'requires_review' => ['sometimes', 'boolean'],
            'office' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['created_at', 'submitted_at', 'last_activity_at', 'reference'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }
}
