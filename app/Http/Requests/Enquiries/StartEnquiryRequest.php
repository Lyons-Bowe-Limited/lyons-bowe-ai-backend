<?php

namespace App\Http\Requests\Enquiries;

use App\Enums\EnquiryPriority;
use App\Enums\PracticeArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'practice_area' => [
                'required',
                'string',
                Rule::in([PracticeArea::Wills->value]),
            ],

            'workflow_key' => [
                'nullable',
                'string',
                'max:255',
            ],

            'conversation_id' => [
                'nullable',
                'integer',
                Rule::exists('ai_conversations', 'id')->where('user_id', $this->user()?->id),
            ],

            'priority' => [
                'nullable',
                'string',
                Rule::enum(EnquiryPriority::class),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'practice_area.required' => 'A practice area is required.',

            'practice_area.in' => 'The selected practice area is not supported.',

            'conversation_id.exists' => 'The selected AI conversation does not exist.',
        ];
    }
}
