<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class RoleSeeder extends Seeder
{
    /**
     * Presets whose allowed tickers are filled in by the owner; re-seeding keeps a filled list.
     *
     * @var list<string>
     */
    private const array OWNER_FILLED_TICKER_ROLES = ['investor'];

    /**
     * Seed the preset agent roles. Re-running restores edited presets and leaves custom roles untouched,
     * except for allowed tickers the owner has filled in on an owner-filled preset.
     */
    public function run(): void
    {
        foreach ($this->roles() as $slug => $attributes) {
            if ($this->hasOwnerFilledTickers($slug)) {
                $attributes = Arr::except($attributes, 'allowed_tickers');
            }

            Role::query()->updateOrCreate(['slug' => $slug], $attributes);
        }
    }

    private function hasOwnerFilledTickers(string $slug): bool
    {
        return in_array($slug, self::OWNER_FILLED_TICKER_ROLES, true)
            && filled(Role::query()->where('slug', $slug)->first()?->allowed_tickers);
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     description: string,
     *     permissions: list<string>,
     *     max_order_quantity: null,
     *     max_order_value: float|int|null,
     *     max_orders_per_day: int|null,
     *     max_daily_loss_percent: float|int|null,
     *     min_portfolio_value: float|int|null,
     *     allowed_tickers: null,
     *     restrict_to_allowed_tickers: bool,
     * }>
     */
    private function roles(): array
    {
        $values = fn (array $permissions): array => array_map(
            fn (Permission $permission): string => $permission->value,
            $permissions,
        );

        return [
            'read-only-analyst' => [
                'name' => 'Read-only analyst',
                'description' => 'Can read account, portfolio, orders, history and instruments on demo accounts. '
                    .'Cannot trade.',
                'permissions' => $values(Permission::readOnly()),
                'max_order_quantity' => null,
                'max_order_value' => null,
                'max_orders_per_day' => null,
                'max_daily_loss_percent' => null,
                'min_portfolio_value' => null,
                'allowed_tickers' => null,
                'restrict_to_allowed_tickers' => false,
            ],
            'demo-trader' => [
                'name' => 'Demo trader',
                'description' => 'Can read and trade (market, limit, stop, sell, cancel) on demo accounts only. '
                    .'Max 20 orders per day.',
                'permissions' => $values([
                    ...Permission::readOnly(),
                    Permission::PlaceMarketOrders,
                    Permission::PlaceLimitOrders,
                    Permission::PlaceStopOrders,
                    Permission::Sell,
                    Permission::CancelOrders,
                ]),
                'max_order_quantity' => null,
                'max_order_value' => null,
                'max_orders_per_day' => 20,
                'max_daily_loss_percent' => null,
                'min_portfolio_value' => null,
                'allowed_tickers' => null,
                'restrict_to_allowed_tickers' => false,
            ],
            'live-trader-restricted' => [
                'name' => 'Live trader (restricted)',
                'description' => 'Full access including LIVE (real money) accounts, '
                    .'limited to orders worth at most 500 and 5 orders per day.',
                'permissions' => $values(Permission::cases()),
                'max_order_quantity' => null,
                'max_order_value' => 500,
                'max_orders_per_day' => 5,
                'max_daily_loss_percent' => null,
                'min_portfolio_value' => null,
                'allowed_tickers' => null,
                'restrict_to_allowed_tickers' => false,
            ],
            'investor' => [
                'name' => 'Investor',
                'description' => 'Can read and place limit, stop and stop-limit orders, sell and cancel on demo accounts. '
                    .'No market orders. Orders worth at most £50, 2 orders per day, BUYs blocked after a 5% daily loss '
                    .'or below £150. Only allowed tickers can be traded: none until the owner fills the list in.',
                'permissions' => $values([
                    ...Permission::readOnly(),
                    Permission::PlaceLimitOrders,
                    Permission::PlaceStopOrders,
                    Permission::Sell,
                    Permission::CancelOrders,
                ]),
                'max_order_quantity' => null,
                'max_order_value' => 50,
                'max_orders_per_day' => 2,
                'max_daily_loss_percent' => 5,
                'min_portfolio_value' => 150,
                'allowed_tickers' => null,
                'restrict_to_allowed_tickers' => true,
            ],
        ];
    }
}
