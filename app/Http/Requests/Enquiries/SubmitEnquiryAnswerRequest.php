<?php

namespace App\Http\Requests\Enquiries;

use Illuminate\Foundation\Http\FormRequest;

class SubmitEnquiryAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'step_key' => [
                'required',
                'string',
                'max:255',
            ],

            'answer' => [
                'present',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'step_key.required' =>
                'The workflow step key is required.',

            'answer.present' =>
                'An answer must be provided.',
        ];
    }
}