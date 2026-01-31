<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for appointments displayed on the client dashboard.
 * Provides a simpler format than the calendar-focused AppointmentResource.
 */
class ClientDashboardAppointmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'date' => $this->date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'status' => $this->status,
            'type' => $this->type,
            'description' => $this->description,
            'artist' => $this->when(
                $this->relationLoaded('artist') && $this->artist,
                fn () => [
                    'id' => $this->artist->id,
                    'name' => $this->artist->name,
                    'slug' => $this->artist->slug,
                    'username' => $this->artist->username,
                    'image' => $this->when(
                        $this->artist->relationLoaded('image') && $this->artist->image,
                        fn () => new BriefImageResource($this->artist->image)
                    ),
                ]
            ),
            'studio' => $this->when(
                $this->relationLoaded('studio') && $this->studio,
                fn () => new BriefStudioResource($this->studio)
            ),
        ];
    }
}
