<?php

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

class ListStaffNotificationsRequest extends FormRequest
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
            'unread' => ['sometimes', 'boolean'],
            'staff_user_id' => ['prohibited'], 'staff_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ];
    }
}
