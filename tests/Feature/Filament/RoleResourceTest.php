<?php

use App\Enums\Permission;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('creates a role with permissions and limits', function () {
    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'Cautious Trader'])
        ->assertSchemaStateSet(['slug' => 'cautious-trader'])
        ->fillForm([
            'description' => 'Small demo trades only',
            'permissions' => [Permission::ViewPortfolio->value, Permission::PlaceLimitOrders->value],
            'max_order_quantity' => 5,
            'max_order_value' => 1000,
            'max_orders_per_day' => 3,
            'allowed_tickers' => ['aapl_us_eq', 'MSFT_US_EQ'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::sole();

    expect($role->slug)->toBe('cautious-trader')
        ->and($role->permissions)
        ->toEqualCanonicalizing([Permission::ViewPortfolio->value, Permission::PlaceLimitOrders->value])
        ->and($role->hasPermission(Permission::TradeLive))->toBeFalse()
        ->and((float) $role->max_order_quantity)->toBe(5.0)
        ->and((float) $role->max_order_value)->toBe(1000.0)
        ->and($role->max_orders_per_day)->toBe(3)
        ->and($role->allowed_tickers)->toBe(['AAPL_US_EQ', 'MSFT_US_EQ']);
});

it('offers every permission with descriptions', function () {
    Livewire::test(CreateRole::class)
        ->assertSee(Permission::TradeLive->getLabel())
        ->assertSee(Permission::TradeLive->getDescription())
        ->assertSee(Permission::CancelOrders->getLabel());
});

it('requires a unique slug', function () {
    Role::factory()->create(['slug' => 'taken']);

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'Taken', 'slug' => 'taken'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('updates permissions and clears limits', function () {
    $role = Role::factory()->readOnly()->create(['max_orders_per_day' => 10, 'allowed_tickers' => ['AAPL_US_EQ']]);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([
            'permissions' => [Permission::ViewAccount->value, Permission::TradeLive->value],
            'max_orders_per_day' => null,
            'allowed_tickers' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $role->refresh();

    expect($role->permissions)->toEqualCanonicalizing([Permission::ViewAccount->value, Permission::TradeLive->value])
        ->and($role->max_orders_per_day)->toBeNull()
        ->and($role->allowed_tickers)->toBeNull()
        ->and($role->allowsTicker('ANY_TICKER'))->toBeTrue();
});

it('saves and clears the loss guards', function () {
    $role = Role::factory()->create();

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm(['max_daily_loss_percent' => 2.5, 'min_portfolio_value' => 150])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh())
        ->max_daily_loss_percent->toBe('2.50')
        ->min_portfolio_value->toBe('150.00');

    Livewire::test(ListRoles::class)
        ->assertTableColumnStateSet('limits', 'daily loss ≤ 2.5% · value ≥ £150', $role);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm(['max_daily_loss_percent' => null, 'min_portfolio_value' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh())
        ->max_daily_loss_percent->toBeNull()
        ->min_portfolio_value->toBeNull();
});

it('validates the loss guards', function (array $data, array $errors) {
    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'Guarded', ...$data])
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(Role::query()->count())->toBe(0);
})->with([
    'percent above 100' => [['max_daily_loss_percent' => 100.01], ['max_daily_loss_percent' => 'max']],
    'negative percent' => [['max_daily_loss_percent' => -1], ['max_daily_loss_percent' => 'min']],
    'negative min value' => [['min_portfolio_value' => -0.01], ['min_portfolio_value' => 'min']],
    'min value above the max' => [['min_portfolio_value' => 1_000_000_000.01], ['min_portfolio_value' => 'max']],
    'non-numeric percent' => [['max_daily_loss_percent' => 'abc'], ['max_daily_loss_percent' => 'numeric']],
]);

it('shows the number of agents per role and protects roles in use', function () {
    $used = Role::factory()->create();
    AiAgent::factory()->count(2)->for($used)->create();
    $unused = Role::factory()->create();

    Livewire::test(ListRoles::class)
        ->assertCanSeeTableRecords([$used, $unused])
        ->assertTableColumnStateSet('ai_agents_count', 2, $used)
        ->assertTableActionDisabled('delete', $used)
        ->assertTableActionEnabled('delete', $unused);
});
