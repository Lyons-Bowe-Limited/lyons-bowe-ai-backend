<?php

namespace App\Http\Requests\Internal;

use Illuminate\Validation\Rule;

class ListMyWorkEnquiriesRequest extends ListEnquiriesRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sort' => ['sometimes', 'required', 'string', Rule::in(['priority', 'created_at', 'last_activity_at', 'submitted_at'])],
            // My Work assignment scope comes exclusively from authenticated staff.
            'assigned_staff_id' => ['prohibited'],
            'staff_id' => ['prohibited'],
            'staff_user_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'unassigned' => ['prohibited'],
        ];
    }
}
