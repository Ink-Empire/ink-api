<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserTagGroupResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'category' => new UserTagCategoryResource($this['category']),
            'tags' => UserTagResource::collection($this['tags']),
        ];
    }
}
