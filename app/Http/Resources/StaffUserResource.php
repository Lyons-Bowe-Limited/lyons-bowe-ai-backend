<?php

namespace App\Http\Resources;

use App\Services\StaffAuthorisationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffUserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $authorisation = app(StaffAuthorisationService::class);

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'email' => $this->email,
            'role' => $authorisation->role($this->resource),
            'status' => $this->status,
            'permissions' => $authorisation->permissions($this->resource)->all(),
        ];
    }
}
