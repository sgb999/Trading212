<?php

namespace App\Data;

use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\TimeValidity;
use BackedEnum;
use InvalidArgumentException;

/**
 * Validated description of an order to place. `quantity` is always positive; `side` decides the sign.
 */
final readonly class OrderRequest
{
    public function __construct(
        public string $ticker,
        public OrderType $type,
        public OrderSide $side,
        public float $quantity,
        public ?float $limitPrice = null,
        public ?float $stopPrice = null,
        public TimeValidity $timeValidity = TimeValidity::Day,
        public bool $extendedHours = false,
    ) {
        if ($this->quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        if ($this->type->requiresLimitPrice() && ($this->limitPrice === null || $this->limitPrice <= 0)) {
            throw new InvalidArgumentException(
                "A positive limit price is required for {$this->type->getLabel()} orders."
            );
        }

        if ($this->type->requiresStopPrice() && ($this->stopPrice === null || $this->stopPrice <= 0)) {
            throw new InvalidArgumentException(
                "A positive stop price is required for {$this->type->getLabel()} orders."
            );
        }

        if (! $this->type->requiresLimitPrice() && $this->limitPrice !== null) {
            throw new InvalidArgumentException(
                "A limit price is not allowed on {$this->type->getLabel()} orders."
            );
        }

        if (! $this->type->requiresStopPrice() && $this->stopPrice !== null) {
            throw new InvalidArgumentException(
                "A stop price is not allowed on {$this->type->getLabel()} orders."
            );
        }
    }

    /**
     * @param  array{
     *     ticker: string,
     *     type: string|OrderType,
     *     side: string|OrderSide,
     *     quantity: float|int|string,
     *     limit_price?: float|int|string|null,
     *     stop_price?: float|int|string|null,
     *     time_validity?: string|TimeValidity|null,
     *     extended_hours?: bool|null,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ticker: trim($data['ticker']),
            type: self::resolveEnum(OrderType::class, $data['type'], 'order type'),
            side: self::resolveEnum(OrderSide::class, $data['side'], 'side'),
            quantity: (float) $data['quantity'],
            limitPrice: self::nullableFloat($data['limit_price'] ?? null),
            stopPrice: self::nullableFloat($data['stop_price'] ?? null),
            timeValidity: filled($data['time_validity'] ?? null)
                ? self::resolveEnum(TimeValidity::class, $data['time_validity'], 'time validity')
                : TimeValidity::Day,
            extendedHours: (bool) ($data['extended_hours'] ?? false),
        );
    }

    /**
     * Resolve a backed enum case from an instance or a (case-insensitive) string value.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enumClass
     * @return TEnum
     *
     * @throws InvalidArgumentException
     */
    private static function resolveEnum(string $enumClass, mixed $value, string $field): BackedEnum
    {
        if ($value instanceof $enumClass) {
            return $value;
        }

        return $enumClass::tryFrom(strtoupper((string) $value))
            ?? throw new InvalidArgumentException("Invalid {$field} [{$value}].");
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    public function signedQuantity(): float
    {
        return $this->side->signedQuantity($this->quantity);
    }
}
