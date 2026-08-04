<?php

namespace App\Http\Requests\Enquiries;

use Illuminate\Foundation\Http\FormRequest;

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
                'in:wills_and_probate',
            ],

            'workflow_key' => [
                'nullable',
                'string',
                'max:255',
            ],

            'conversation_id' => [
                'nullable',
                'integer',
                'exists:ai_conversations,id',
            ],

            'priority' => [
                'nullable',
                'string',
                'in:low,normal,high,urgent',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'practice_area.required' =>
                'A practice area is required.',

            'practice_area.in' =>
                'The selected practice area is not supported.',

            'conversation_id.exists' =>
                'The selected AI conversation does not exist.',
        ];
    }
}