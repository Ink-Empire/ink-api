<?php

namespace App\Enums;

/**
 * Which part of the studio page a section sits in.
 *
 * Feature is the strip above the Portfolio and Info tabs, so it is on screen
 * the moment a visitor arrives. Info is inside the Info tab, a click away.
 * That difference is the whole point of the split, and it is why moving a
 * section between the two is a real editorial decision rather than styling.
 *
 * Storage is sparse: the layout still decides where a section starts, and only
 * a section the studio has actually moved has an entry here.
 */
enum StudioSectionBand: string
{
    case Feature = 'feature';
    case Info = 'info';

    public function label(): string
    {
        return $this === self::Feature ? 'Always visible' : 'Info tab';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
