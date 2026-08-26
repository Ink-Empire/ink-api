<?php

namespace App\Enums;

/**
 * Which of a band's two stacks a half-width section sits in.
 *
 * The stacks pack independently, so a tall card on one side no longer holds a
 * short card on the other side down. Only half-width sections have a column at
 * all: a full-width one spans both and interrupts the stacks.
 *
 * Storage is sparse. A section nobody has placed has no entry, and the client
 * falls back to alternating left and right down the band, which is exactly
 * what the row grid this replaced produced.
 */
enum StudioSectionColumn: string
{
    case Left = 'left';
    case Right = 'right';

    public function label(): string
    {
        return $this === self::Left ? 'Left column' : 'Right column';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
