<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderSide: string implements HasColor, HasLabel
{
    case Buy = 'BUY';
    case Sell = 'SELL';

    /**
     * Trading 212 expresses sells as a negative quantity.
     */
    public function signedQuantity(float $quantity): float
    {
        return $this === self::Sell ? -abs($quantity) : abs($quantity);
    }

    public function getLabel(): string
    {
        return ucfirst(strtolower($this->value));
    }

    public function getColor(): string
    {
        return $this === self::Buy ? 'success' : 'danger';
    }
}
