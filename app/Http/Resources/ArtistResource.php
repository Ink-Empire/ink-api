<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ArtistResource extends JsonResource
{
    protected $user_id;

    public function user_id($value): static
    {
        $this->user_id = $value;
        return $this;
    }

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'about' => $this->about,
            'email' => $this->email,
            'image' => $this->image,
            'location' => $this->location,
            'name' => $this->name,
            'password' => $this->password,
            'phone' => $this->phone,
            'studio' => $this->studio,
            'type' => $this->type->name,
            'styles' => $this->styles,
            'tattoos' => $this->tattoos,
            'isFavorite' => $this->getIsUserFavorite(),
        ];
    }


    private function getIsUserFavorite(): bool
    {
        if (session('user_id')) {
            if (in_array(session('user_id'), $this->users->pluck('id')->toArray())) {
                return true;
            }
        }
        return false;
    }
}
