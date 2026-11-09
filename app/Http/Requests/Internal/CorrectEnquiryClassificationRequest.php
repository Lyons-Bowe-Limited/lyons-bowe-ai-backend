<?php

namespace App\Http\Requests\Internal;

use App\Enums\PracticeArea;
use App\Services\Enquiries\EnquiryClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CorrectEnquiryClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'practice_area' => ['required', Rule::enum(PracticeArea::class)],
            'matter_type' => ['present', 'nullable', 'string', 'max:255'],
            'requires_review' => ['required', 'boolean'],
            'handoff_reason' => ['sometimes', 'nullable', Rule::in(['staff_classification_confirmed', 'classification_requires_review', 'contact_details_incomplete', 'qualification_confidence_too_low', 'staff_review_required'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! app(EnquiryClassification::class)->isValid($this->input('practice_area'), $this->input('matter_type'))) {
                $validator->errors()->add('matter_type', 'The matter type must be canonical and belong to the selected practice area; unclassified requires a null matter type.');
            }
            if ($this->input('practice_area') === PracticeArea::Unclassified->value && ! $this->boolean('requires_review')) {
                $validator->errors()->add('requires_review', 'Unclassified enquiries require review.');
            }
            if ($this->boolean('requires_review') && $this->input('handoff_reason') === 'staff_classification_confirmed') {
                $validator->errors()->add('handoff_reason', 'Use a review reason while review is required.');
            }
            if (! $this->boolean('requires_review') && $this->filled('handoff_reason') && $this->input('handoff_reason') !== 'staff_classification_confirmed') {
                $validator->errors()->add('handoff_reason', 'A review reason requires requires_review to be true.');
            }
        }];
    }
}
