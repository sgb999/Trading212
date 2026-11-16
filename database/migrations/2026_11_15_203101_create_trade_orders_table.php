<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_id')->nullable()->index();
            $table->string('ticker')->index();
            $table->string('type', 20);
            $table->string('side', 4);
            $table->decimal('quantity', 20, 8);
            $table->decimal('limit_price', 20, 8)->nullable();
            $table->decimal('stop_price', 20, 8)->nullable();
            $table->string('time_validity', 20)->nullable();
            $table->boolean('extended_hours')->default(false);
            $table->string('status', 20)->index();
            $table->string('external_status', 30)->nullable();
            $table->nullableMorphs('placed_by');
            $table->jsonb('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_orders');
    }
};
