<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Trading212Environment: string implements HasColor, HasLabel
{
    case Demo = 'demo';
    case Live = 'live';

    public function baseUrl(): string
    {
        return config("trading212.base_urls.{$this->value}");
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Demo => 'Demo (paper)',
            self::Live => 'Live (real money)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Demo => 'info',
            self::Live => 'danger',
        };
    }
}
