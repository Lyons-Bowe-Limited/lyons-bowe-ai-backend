<?php

namespace App\Http\Requests\Internal;

class ListMyWorkEnquiriesRequest extends ListEnquiriesRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // My Work assignment scope comes exclusively from authenticated staff.
            'assigned_staff_id' => ['prohibited'],
            'staff_id' => ['prohibited'],
            'staff_user_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'unassigned' => ['prohibited'],
        ];
    }
}
