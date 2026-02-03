<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StudioStatsResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'page_views' => [
                'count' => $this->resource['page_views']['count'] ?? 0,
                'trend' => $this->resource['page_views']['trend'] ?? 0,
                'trend_label' => $this->resource['page_views']['trend_label'] ?? '+0%',
            ],
            'bookings' => [
                'count' => $this->resource['bookings']['count'] ?? 0,
                'trend' => $this->resource['bookings']['trend'] ?? 0,
                'trend_label' => $this->resource['bookings']['trend_label'] ?? '+0',
            ],
            'inquiries' => [
                'count' => $this->resource['inquiries']['count'] ?? 0,
                'trend' => $this->resource['inquiries']['trend'] ?? 0,
                'trend_label' => $this->resource['inquiries']['trend_label'] ?? '',
            ],
            'artists_count' => $this->resource['artists_count'] ?? 0,
        ];
    }
}
