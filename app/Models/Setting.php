<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Application-wide runtime settings stored as key/value rows in the database, so they survive cache
 * clears and can be changed from the admin panel without a deploy.
 *
 * @property string $key
 * @property mixed $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * Global kill switch: while true, every AI agent order is denied.
     */
    public const string AGENT_TRADING_HALTED = 'agent_trading_halted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function valueOf(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->first()?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function isAgentTradingHalted(): bool
    {
        return static::valueOf(self::AGENT_TRADING_HALTED, false) === true;
    }

    public static function setAgentTradingHalted(bool $halted): void
    {
        static::put(self::AGENT_TRADING_HALTED, $halted);
    }
}
