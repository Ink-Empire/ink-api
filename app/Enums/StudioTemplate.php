<?php

namespace App\Enums;

/**
 * How a studio's public page is laid out.
 *
 * Deliberately a small set of hand-built arrangements rather than a free-form
 * block builder: a marketplace's value partly comes from listings being
 * comparable, and a studio owner should not have to design a page.
 */
enum StudioTemplate: string
{
    case Portfolio = 'portfolio';
    case Team = 'team';
    case Storefront = 'storefront';

    public function label(): string
    {
        return match ($this) {
            self::Portfolio => 'Portfolio',
            self::Team => 'Team',
            self::Storefront => 'Storefront',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Portfolio => 'Leads with the work. Best if your tattoos speak for you.',
            self::Team => 'Leads with your artists. Best for a shop with a few names people ask for.',
            self::Storefront => 'Leads with hours and how to reach you. Best if you take walk-ins.',
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
