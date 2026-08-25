<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An announcement or guide as the studio page and its clients render it.
 */
class StudioPostResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'published_at' => $this->published_at,

            // Only the substantive types get a page of their own; ephemeral
            // notices render inline and nowhere else.
            'url' => $this->when(
                $this->type->hasPublicPage() && $this->slug && $this->studio,
                fn () => "/studios/{$this->studio->slug}/news/{$this->slug}"
            ),
        ];
    }
}
