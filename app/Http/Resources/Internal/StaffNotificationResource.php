<?php

namespace App\Http\Resources\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'staff_user_id' => $this->staff_user_id, 'type' => $this->type,
            'title' => $this->title, 'message' => $this->message, 'enquiry_id' => $this->enquiry_id,
            'read_at' => $this->read_at, 'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
