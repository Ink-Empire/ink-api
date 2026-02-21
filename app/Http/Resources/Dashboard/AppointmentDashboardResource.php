<?php

namespace App\Http\Resources\Dashboard;

use App\Http\Resources\BriefImageResource;
use App\Http\Resources\BriefStudioResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for appointments displayed on the client dashboard.
 * Provides a simpler format than the calendar-focused AppointmentResource.
 */
class AppointmentDashboardResource extends JsonResource
{
    private function formatTitle(): string
    {
        $type = $this->type === 'consultation' ? 'Consultation' : 'Appointment';
        $artistName = $this->relationLoaded('artist') && $this->artist
            ? $this->artist->name
            : null;

        return $artistName
            ? "Tattoo {$type} with {$artistName}"
            : "Tattoo {$type}";
    }

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->formatTitle(),
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
