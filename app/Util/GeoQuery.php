<?php

namespace App\Util;

class GeoQuery
{
    /**
     * Build a geo_distance clause for an Elasticsearch bool query.
     *
     * This bypasses QueryBuilder::whereDistance(), which calls a service method
     * that does not exist in the installed version of ink-empire/elastic and
     * fatals on every distance search. The clause is appended directly to the
     * builder's public $bool property, which is all whereDistance() ever did.
     *
     * @param  string $col      Geo point field, dotted for a nested field
     * @param  mixed  $lat
     * @param  mixed  $lon
     * @param  string $distance Distance with unit suffix (e.g. "50mi", "25km")
     * @return array
     */
    public static function distanceClause(string $col, $lat, $lon, string $distance): array
    {
        $geoQuery = [
            'geo_distance' => [
                'distance' => $distance,
                $col => [
                    'lat' => $lat,
                    'lon' => $lon
                ]
            ]
        ];

        if (!str_contains($col, '.')) {
            return $geoQuery;
        }

        return [
            'nested' => [
                'path' => substr($col, 0, strrpos($col, ".")),
                'query' => $geoQuery
            ]
        ];
    }
}
