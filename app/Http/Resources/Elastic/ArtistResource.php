<?php

namespace App\Http\Resources\Elastic;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\WorkingHoursResource;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtistResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();
        $canViewPrivate = $this->canViewPrivateDetails($user);

        $data = [
            'id' => $this->id,
            'about' => $this->about,
            'image' => $this->image,
            'location' => $this->location,
            'name' => $this->name,
            'slug' => $this->slug,
            'studio' => $this->getStudioName(),
            'type' => $this->type->name ?? null,
            'is_featured' => (int) $this->is_featured,
            'styles' => $this->whenLoaded('styles'),
            'isFavorite' => $this->getIsUserFavorite(),
            'username' => $this->username,
            'working_hours' => $this->whenLoaded('working_hours', fn() => WorkingHoursResource::collection($this->working_hours)),
            'appointments' => $this->whenLoaded('appointments', fn() => AppointmentResource::collection($this->appointments)),
            // Only include public settings (books_open status)
            'settings' => $this->getPublicSettings(),
        ];

        // Only include sensitive fields for authorized users
        if ($canViewPrivate) {
            $data['email'] = $this->email;
            $data['phone'] = $this->phone;
            $data['location_lat_long'] = $this->location_lat_long;
        }

        return $data;
    }

    /**
     * Check if the current user can view private details.
     * Private details are visible to the artist themselves or admins.
     */
    private function canViewPrivateDetails($user): bool
    {
        if (!$user) {
            return false;
        }

        // Artist viewing their own profile
        if ($user->id === $this->id) {
            return true;
        }

        // Admin users
        if ($user->is_admin) {
            return true;
        }

        return false;
    }

    /**
     * Return only public-safe settings (excludes rates/financial info).
     */
    private function getPublicSettings(): array
    {
        $settings = $this->settings;
        if (!$settings) {
            return [];
        }

        // Only expose non-sensitive booking availability settings
        return [
            'books_open' => $settings->books_open ?? false,
            'accepts_walk_ins' => $settings->accepts_walk_ins ?? false,
            'accepts_consultations' => $settings->accepts_consultations ?? false,
            'accepts_appointments' => $settings->accepts_appointments ?? false,
        ];
    }

    private function getIsUserFavorite(): bool
    {
        if (session('user_id') && $this->users) {
            if (in_array(session('user_id'), $this->users->pluck('id')->toArray())) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get studio name, handling both Elasticsearch array data and Eloquent relationships.
     */
    private function getStudioName(): string
    {
        $studio = $this->studio;

        // Elasticsearch array data - studio is an object/array
        if (is_array($studio) || is_object($studio) && !($studio instanceof \Illuminate\Support\Collection)) {
            return $studio->name ?? $studio['name'] ?? '';
        }

        // Eloquent belongsToMany collection - get first studio's name
        if ($studio instanceof \Illuminate\Support\Collection) {
            return $studio->first()?->name ?? '';
        }

        return '';
    }
}
