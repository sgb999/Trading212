<?php

namespace App\Livewire;

use App\Filament\Support\ActiveTradingAccount;
use App\Models\TradingAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Topbar dropdown for choosing which trading account (demo / live) the UI works on.
 */
class TradingAccountSwitcher extends Component
{
    #[Computed]
    public function activeAccount(): ?TradingAccount
    {
        return ActiveTradingAccount::get();
    }

    /**
     * @return Collection<int, TradingAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return auth()->user()->tradingAccounts()->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function switchTo(int $accountId): void
    {
        $account = auth()->user()->tradingAccounts()->findOrFail($accountId);

        ActiveTradingAccount::set($account);

        $this->redirect(url()->previous(filament()->getUrl()));
    }

    public function render(): View
    {
        return view('livewire.trading-account-switcher');
    }
}
