<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TimeValidity: string implements HasLabel
{
    case Day = 'DAY';
    case GoodTillCancel = 'GOOD_TILL_CANCEL';

    public function getLabel(): string
    {
        return match ($this) {
            self::Day => 'Day',
            self::GoodTillCancel => 'Good till cancelled',
        };
    }
}
