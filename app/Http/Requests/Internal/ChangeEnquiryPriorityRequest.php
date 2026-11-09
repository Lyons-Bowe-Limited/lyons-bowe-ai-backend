<?php

namespace App\Http\Requests\Internal;

use App\Enums\EnquiryPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeEnquiryPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return ['priority' => ['required', Rule::enum(EnquiryPriority::class)], 'reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
