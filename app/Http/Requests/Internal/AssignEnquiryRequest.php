<?php

namespace App\Http\Requests\Internal;

use App\Models\StaffUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['assigned_staff_id' => ['present', 'nullable', 'integer', Rule::exists('staff_users', 'id')->where('status', StaffUser::STATUS_ACTIVE)]];
    }
}
