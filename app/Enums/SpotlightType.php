<?php

namespace App\Enums;

/**
 * What a studio can pin to the top of its page.
 */
enum SpotlightType: string
{
    case Artist = 'artist';
    case Tattoo = 'tattoo';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
