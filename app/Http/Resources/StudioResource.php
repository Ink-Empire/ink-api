<?php

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Resources\Json\JsonResource;

class StudioResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'about' => $this->about,
            'location' => $this->location,
            'location_lat_long' => $this->location_lat_long,
            'address' => $this->address?->address1,
            'address2' => $this->address?->address2,
            'city' => $this->address?->city,
            'state' => $this->address?->state,
            'postal_code' => $this->address?->postal_code,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'image' => $this->getImage(),
            'is_verified' => $this->is_verified,
            'is_claimed' => (bool) $this->is_claimed,
            'business_hours' => $this->business_hours,
            'hours' => $this->getFormattedHours(),
            'owner_id' => $this->owner_id,
            'seeking_guest_artists' => (bool) $this->seeking_guest_artists,
            'guest_spot_details' => $this->guest_spot_details,
            'announcements' => $this->whenLoaded('activeAnnouncements'),
            'artists' => UserResource::collection($this->whenLoaded('artists')),
        ];
    }

    private function getImage()
    {
        if(!$this->image_id){
            $image = new Image();
            $image->setUriAttribute(); //i wish this would automatically trigger but it wont unless you set it
            return $image;
        } else {
            return $this->image;
        }
    }

    private function getFormattedHours(): array
    {
        if (!$this->business_hours || $this->business_hours->isEmpty()) {
            return [];
        }

        return $this->business_hours->map(function ($hour) {
            $openTime = $hour->open_time ? date('g:i A', strtotime($hour->open_time)) : null;
            $closeTime = $hour->close_time ? date('g:i A', strtotime($hour->close_time)) : null;

            return [
                'day' => $hour->day,
                'day_id' => $hour->day_id,
                'open_time' => $hour->open_time,
                'close_time' => $hour->close_time,
                'hours' => $openTime && $closeTime ? "{$openTime} - {$closeTime}" : 'Closed',
            ];
        })->toArray();
    }
}
