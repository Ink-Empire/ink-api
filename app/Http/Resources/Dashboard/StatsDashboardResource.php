<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Resources\Json\JsonResource;

class StatsDashboardResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'page_views' => [
                'count' => $data['page_views']['count'] ?? 0,
                'trend' => $data['page_views']['trend'] ?? 0,
                'trend_label' => $data['page_views']['trend_label'] ?? '+0%',
            ],
            'bookings' => [
                'count' => $data['bookings']['count'] ?? 0,
                'trend' => $data['bookings']['trend'] ?? 0,
                'trend_label' => $data['bookings']['trend_label'] ?? '+0',
            ],
            'inquiries' => [
                'count' => $data['inquiries']['count'] ?? 0,
                'trend' => $data['inquiries']['trend'] ?? 0,
                'trend_label' => $data['inquiries']['trend_label'] ?? '',
            ],
            'artists_count' => $data['artists_count'] ?? 0,
        ];
    }
}
