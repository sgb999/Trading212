<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the preset agent roles. Re-running restores edited presets and leaves custom roles untouched.
     */
    public function run(): void
    {
        foreach ($this->roles() as $slug => $attributes) {
            Role::query()->updateOrCreate(['slug' => $slug], $attributes);
        }
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     description: string,
     *     permissions: list<string>,
     *     max_order_quantity: null,
     *     max_order_value: float|null,
     *     max_orders_per_day: int|null,
     *     allowed_tickers: null,
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
                'allowed_tickers' => null,
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
                'allowed_tickers' => null,
            ],
            'live-trader-restricted' => [
                'name' => 'Live trader (restricted)',
                'description' => 'Full access including LIVE (real money) accounts, '
                    .'limited to orders worth at most 500 and 5 orders per day.',
                'permissions' => $values(Permission::cases()),
                'max_order_quantity' => null,
                'max_order_value' => 500,
                'max_orders_per_day' => 5,
                'allowed_tickers' => null,
            ],
        ];
    }
}
