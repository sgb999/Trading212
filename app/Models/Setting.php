<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /**
     * Global kill switch: while true, every AI agent order and cancellation is denied.
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

    /**
     * Fails closed: a missing row means not halted, but any stored value that is not recognisably
     * false (e.g. 1, "true", null, an array or garbage) means halted.
     */
    public static function isAgentTradingHalted(): bool
    {
        $setting = static::query()->where('key', self::AGENT_TRADING_HALTED)->first();

        if ($setting === null) {
            return false;
        }

        if (! is_scalar($setting->value)) {
            return true;
        }

        return filter_var($setting->value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    public static function setAgentTradingHalted(bool $halted): void
    {
        static::put(self::AGENT_TRADING_HALTED, $halted);
    }
}
