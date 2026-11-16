<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class UserSeeder extends Seeder
{
    /**
     * Seed a local admin user outside production. Safe to re-run.
     */
    public function run(): void
    {
        if (App::isProduction()) {
            return;
        }

        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => 'password', 'email_verified_at' => now()],
        );
    }
}
