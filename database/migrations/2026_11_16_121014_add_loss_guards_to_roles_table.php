<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->decimal('max_daily_loss_percent', 5, 2)->nullable()->after('max_orders_per_day');
            $table->decimal('min_portfolio_value', 20, 2)->nullable()->after('max_daily_loss_percent');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['max_daily_loss_percent', 'min_portfolio_value']);
        });
    }
};
