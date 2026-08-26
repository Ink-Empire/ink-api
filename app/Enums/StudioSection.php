<?php

namespace App\Enums;

/**
 * A block a studio owner can move around their page.
 *
 * The banner, the studio header and the portfolio are deliberately absent:
 * they are the page's structure rather than widgets. Announcements are pinned
 * for the same reason, above the name and photo, because they are meant to be
 * the first thing a visitor reads.
 */
enum StudioSection: string
{
    // Case order is the default page order, for a studio that has never
    // rearranged anything. It reproduces the arrangement every layout shipped
    // with, so adopting this changed nobody's page.
    case Artists = 'artists';
    case Location = 'location';
    case Hours = 'hours';
    case Guides = 'guides';
    case Contact = 'contact';
    case Spotlight = 'spotlight';

    public function label(): string
    {
        return match ($this) {
            self::Artists => 'Artists',
            self::Location => 'Location',
            self::Hours => 'Hours',
            self::Guides => 'Guides',
            self::Contact => 'Contact',
            self::Spotlight => 'Spotlight',
        };
    }

    /**
     * The width a section takes when a studio has never resized it.
     *
     * Spotlight and the artist list are lists of their own and read badly in
     * one column; the rest are short cards that pair up.
     */
    public function defaultWidth(): StudioSectionWidth
    {
        return match ($this) {
            self::Artists, self::Spotlight => StudioSectionWidth::Full,
            default => StudioSectionWidth::Half,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
