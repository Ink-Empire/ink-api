<?php

namespace App\Enums;

/**
 * How much of a band a section takes up.
 *
 * Both bands on a studio page are two-column grids, so a section is either one
 * column or the whole width. There is no third size on purpose: a marketplace
 * page stays comparable, and an owner dragging an edge should land somewhere
 * good rather than somewhere arbitrary.
 */
enum StudioSectionWidth: string
{
    case Half = 'half';
    case Full = 'full';

    public function columns(): int
    {
        return $this === self::Full ? 2 : 1;
    }

    public function label(): string
    {
        return $this === self::Full ? 'Full width' : 'Half width';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
