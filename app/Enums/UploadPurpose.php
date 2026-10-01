<?php

namespace App\Enums;

/**
 * What a directly uploaded file is for.
 *
 * The value is the first segment of every filename the presign endpoints
 * issue, so it is part of the wire format rather than a display label.
 */
enum UploadPurpose: string
{
    case Tattoo = 'tattoo';
    case Profile = 'profile';
    case Studio = 'studio';
    case Message = 'message';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
