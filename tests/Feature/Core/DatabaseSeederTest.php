<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\TradingAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

test('it seeds a local admin and the preset roles and can be re-run', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@example.com')->sole();
    expect(Hash::check('password', $admin->password))->toBeTrue()
        ->and(Role::query()->count())->toBe(3)
        ->and(TradingAccount::query()->count())->toBe(0);
});

test('the preset roles grant the documented permissions and limits', function () {
    $this->seed(DatabaseSeeder::class);
    $roles = Role::query()->get()->keyBy('name');

    $analyst = $roles['Read-only analyst'];
    $demoTrader = $roles['Demo trader'];
    $liveTrader = $roles['Live trader (restricted)'];

    expect($analyst->permissions)
        ->toEqualCanonicalizing(array_map(fn (Permission $p) => $p->value, Permission::readOnly()))
        ->and($analyst->hasPermission(Permission::PlaceMarketOrders))->toBeFalse()
        ->and($demoTrader->hasPermission(Permission::PlaceMarketOrders))->toBeTrue()
        ->and($demoTrader->hasPermission(Permission::PlaceLimitOrders))->toBeTrue()
        ->and($demoTrader->hasPermission(Permission::PlaceStopOrders))->toBeTrue()
        ->and($demoTrader->hasPermission(Permission::Sell))->toBeTrue()
        ->and($demoTrader->hasPermission(Permission::CancelOrders))->toBeTrue()
        ->and($demoTrader->hasPermission(Permission::ViewPortfolio))->toBeTrue()
        ->and($demoTrader->hasPermission(Permission::TradeLive))->toBeFalse()
        ->and($demoTrader->max_orders_per_day)->toBe(20)
        ->and($demoTrader->max_order_value)->toBeNull()
        ->and($liveTrader->permissions)
        ->toEqualCanonicalizing(array_map(fn (Permission $p) => $p->value, Permission::cases()))
        ->and($liveTrader->max_order_value)->toBe('500.00')
        ->and($liveTrader->max_orders_per_day)->toBe(5);
});

test('re-seeding restores edited preset roles without touching custom ones', function () {
    $this->seed(DatabaseSeeder::class);
    Role::query()->where('name', 'Demo trader')->update(['max_orders_per_day' => 999]);
    $custom = Role::factory()->create();

    $this->seed(DatabaseSeeder::class);

    expect(Role::query()->where('name', 'Demo trader')->value('max_orders_per_day'))->toBe(20)
        ->and($custom->fresh())->not->toBeNull();
});
