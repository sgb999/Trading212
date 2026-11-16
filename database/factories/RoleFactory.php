<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
            'permissions' => array_map(fn (Permission $p) => $p->value, Permission::readOnly()),
        ];
    }

    /**
     * @param  list<Permission>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => ['permissions' => array_map(fn (Permission $p) => $p->value, $permissions)]);
    }

    /**
     * Loss guards on agent BUY orders; null disables a guard.
     */
    public function withLossGuards(?float $maxDailyLossPercent = 5, ?float $minPortfolioValue = null): static
    {
        return $this->state(fn () => [
            'max_daily_loss_percent' => $maxDailyLossPercent,
            'min_portfolio_value' => $minPortfolioValue,
        ]);
    }

    public function readOnly(): static
    {
        return $this->withPermissions(Permission::readOnly());
    }

    public function trader(): static
    {
        return $this->withPermissions(array_values(array_filter(
            Permission::cases(),
            fn (Permission $p) => $p !== Permission::TradeLive,
        )));
    }

    public function fullAccess(): static
    {
        return $this->withPermissions(Permission::cases());
    }
}
