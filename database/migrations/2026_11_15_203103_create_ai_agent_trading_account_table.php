<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_trading_account', function (Blueprint $table) {
            $table->foreignId('ai_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->primary(['ai_agent_id', 'trading_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_trading_account');
    }
};
