<div class="flex items-center">
    @php($active = $this->activeAccount)

    @if ($this->accounts->isEmpty())
        <x-filament::button
            :href="\App\Filament\Resources\TradingAccounts\TradingAccountResource::getUrl('create')"
            tag="a"
            size="sm"
            icon="heroicon-m-plus"
            outlined
        >
            Connect a Trading 212 account
        </x-filament::button>
    @else
        <x-filament::dropdown placement="bottom-end" teleport data-testid="account-switcher">
            <x-slot name="trigger">
                <button
                    type="button"
                    @class([
                        'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition',
                        'bg-danger-50 text-danger-700 ring-danger-600/30 hover:bg-danger-100 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-400/30' => $active?->isLive(),
                        'bg-white text-gray-700 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10' => ! $active?->isLive(),
                    ])
                    aria-label="Switch trading account"
                >
                    @if ($active?->isLive())
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4 text-danger-600 dark:text-danger-400" />
                    @else
                        <x-filament::icon icon="heroicon-m-beaker" class="h-4 w-4 text-info-600 dark:text-info-400" />
                    @endif

                    <span class="max-w-40 truncate">{{ $active?->name }}</span>

                    <x-filament::badge :color="$active?->environment->getColor()" size="sm">
                        {{ $active?->isLive() ? 'LIVE' : 'DEMO' }}
                    </x-filament::badge>

                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 opacity-60" />
                </button>
            </x-slot>

            <x-filament::dropdown.header icon="heroicon-m-arrows-right-left">
                Active trading account
            </x-filament::dropdown.header>

            <x-filament::dropdown.list>
                @foreach ($this->accounts as $account)
                    <x-filament::dropdown.list.item
                        wire:click="switchTo({{ $account->id }})"
                        :badge="$account->isLive() ? 'LIVE' : 'DEMO'"
                        :badge-color="$account->environment->getColor()"
                        :color="$account->isLive() ? 'danger' : 'gray'"
                        :icon="$account->is($active) ? 'heroicon-m-check-circle' : ($account->isLive() ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-beaker')"
                    >
                        {{ $account->name }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
</div>
