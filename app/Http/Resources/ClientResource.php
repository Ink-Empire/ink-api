<?php

namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'location' => $this->location,
            'name' => $this->name,
            'phone' => $this->phone,
            'username' => $this->username,
        ];
    }
}
