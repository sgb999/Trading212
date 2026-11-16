@php($account = \App\Filament\Support\ActiveTradingAccount::get())

@if ($account?->isLive())
    <div
        role="alert"
        data-testid="live-account-banner"
        class="mb-4 flex items-center gap-3 rounded-xl bg-danger-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm"
    >
        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5 shrink-0" />
        <span>
            You are working on the <strong>LIVE</strong> account “{{ $account->name }}”. Orders use real money.
        </span>
    </div>
@endif
