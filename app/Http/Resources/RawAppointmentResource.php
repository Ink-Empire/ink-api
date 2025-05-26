<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RawAppointmentResource extends JsonResource
{
    /**
     * Enable default data wrapping for Laravel best practices
     */

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'client_id' => $this->client_id,
            'artist_id' => $this->artist_id,
            'studio_id' => $this->studio_id,
            'tattoo_id' => $this->tattoo_id,
            'date' => $this->date,
            'status' => $this->status,
            'type' => $this->type,
            'all_day' => $this->all_day,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'client' => $this->when($this->relationLoaded('client'), function () {
                return [
                    'id' => $this->client->id,
                    'username' => $this->client->username,
                    'email' => $this->client->email,
                    'name' => $this->client->name,
                ];
            }),
        ];
    }
}