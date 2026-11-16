<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_account_id');
            $table->decimal('total_value', 20, 4);
            $table->decimal('cash', 20, 4);
            $table->decimal('invested', 20, 4);
            $table->string('currency', 3)->nullable();
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->index(['trading_account_id', 'taken_at']);
        });

        Schema::table('trading_accounts', function (Blueprint $table) {
            // No ON DELETE action: a baseline snapshot cannot be deleted on its own, only together with its account.
            $table->foreignId('baseline_snapshot_id')->nullable()->constrained('portfolio_snapshots');
        });
    }

    public function down(): void
    {
        Schema::table('trading_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('baseline_snapshot_id');
        });

        Schema::dropIfExists('portfolio_snapshots');
    }
};
