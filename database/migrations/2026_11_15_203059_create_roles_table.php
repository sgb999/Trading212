<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->jsonb('permissions')->default('[]');
            $table->decimal('max_order_quantity', 20, 8)->nullable();
            $table->decimal('max_order_value', 20, 2)->nullable();
            $table->unsignedInteger('max_orders_per_day')->nullable();
            $table->jsonb('allowed_tickers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
