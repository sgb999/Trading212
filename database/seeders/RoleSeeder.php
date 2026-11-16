<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Presets the owner tunes by hand (tickers, TradeLive, limits): seeded once, never overwritten.
     *
     * @var list<string>
     */
    private const array OWNER_MANAGED_ROLES = ['investor'];

    /**
     * Seed the preset agent roles. Re-running restores edited presets and leaves custom roles untouched.
     * Owner-managed presets are only created when missing; an existing one is left exactly as the owner set it.
     */
    public function run(): void
    {
        foreach ($this->roles() as $slug => $attributes) {
            if (in_array($slug, self::OWNER_MANAGED_ROLES, true)) {
                Role::query()->firstOrCreate(['slug' => $slug], $attributes);

                continue;
            }

            Role::query()->updateOrCreate(['slug' => $slug], $attributes);
        }
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     description: string,
     *     permissions: list<string>,
     *     max_order_quantity: null,
     *     max_order_value: int|null,
     *     max_orders_per_day: int|null,
     *     max_daily_loss_percent: int|null,
     *     min_portfolio_value: int|null,
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
