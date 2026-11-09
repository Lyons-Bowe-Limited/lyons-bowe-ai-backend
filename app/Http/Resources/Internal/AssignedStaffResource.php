<?php

namespace App\Http\Resources\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignedStaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'first_name' => $this->first_name, 'last_name' => $this->last_name,
            'name' => trim($this->first_name.' '.$this->last_name), 'email' => $this->email, 'status' => $this->status];
    }
}
