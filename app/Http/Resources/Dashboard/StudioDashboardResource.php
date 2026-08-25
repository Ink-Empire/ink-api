<?php

namespace App\Http\Resources\Dashboard;

use App\Enums\StudioTemplate;
use App\Http\Resources\BriefImageResource;
use App\Models\Image;
use Illuminate\Http\Resources\Json\JsonResource;

class StudioDashboardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
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
            'image' => $this->getImage(),
            'banner' => $this->bannerImage,
            'template' => ($this->template ?? StudioTemplate::Portfolio)->value,
            'is_verified' => $this->is_verified,
            'is_claimed' => (bool) $this->is_claimed,
            'hours' => $this->formattedHours(),
            'owner_id' => $this->owner_id,
            'seeking_guest_artists' => (bool) $this->seeking_guest_artists,
            'guest_spot_details' => $this->guest_spot_details,
            'artists' => $this->getArtists(),
            'announcements' => $this->getAnnouncements(),
            'stats' => $this->getStats(),
            'working_hours' => $this->getWorkingHours(),
        ];
    }

    private function getImage()
    {
        if (!$this->image_id) {
            $image = new Image();
            $image->setUriAttribute();
            return new BriefImageResource($image);
        }
        return new BriefImageResource($this->image);
    }

    private function getArtists()
    {
        $artists = $this->getAttribute('dashboard_artists') ?? [];
        return StudioArtistDashboardResource::collection(collect($artists));
    }

    private function getAnnouncements()
    {
        return $this->announcements ?? [];
    }

    private function getStats()
    {
        $stats = $this->getAttribute('dashboard_stats') ?? [];
        return new StatsDashboardResource($stats);
    }

    private function getWorkingHours()
    {
        $hours = $this->getAttribute('dashboard_working_hours') ?? $this->workingHours ?? collect();
        return WorkingHoursDashboardResource::collection($hours);
    }
}
