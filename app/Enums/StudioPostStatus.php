<?php

namespace App\Enums;

enum StudioPostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
