<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SocialMediaLinkResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'platform' => $this->platform,
            'username' => $this->username,
            'url' => $this->url,
        ];
    }
}
