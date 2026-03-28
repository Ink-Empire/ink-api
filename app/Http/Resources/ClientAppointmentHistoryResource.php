<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientAppointmentHistoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'date' => $this->date->toDateString(),
            'duration_minutes' => $this->duration_minutes,
            'notes' => $this->notes,
            'status' => $this->date->isFuture() ? 'upcoming' : ($this->status === 'completed' ? 'done' : $this->status),
        ];
    }
}
