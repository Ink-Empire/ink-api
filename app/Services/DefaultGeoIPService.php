<?php

namespace App\Services;

use Torann\GeoIP\Location;
use Torann\GeoIP\Services\AbstractService;

class DefaultGeoIPService extends AbstractService
{
    /**
     * {@inheritdoc}
     */
    public function locate($ip = null)
    {
        // Always return the default location from config
        return $this->hydrate(config('geoip.default_location', [
            'ip' => $ip ?: '127.0.0.1',
            'iso_code' => 'US',
            'country' => 'United States',
            'city' => 'New Haven',
            'state' => 'CT',
            'state_name' => 'Connecticut',
            'postal_code' => '06510',
            'lat' => 41.31,
            'lon' => -72.92,
            'timezone' => 'America/New_York',
            'currency' => 'USD',
        ]));
    }
}