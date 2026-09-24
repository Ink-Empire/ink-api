<?php

namespace App\Enums;

enum StudioHoldStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnHold => 'On hold',
        };
    }
}
