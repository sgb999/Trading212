<x-filament-panels::page>
    <x-filament::tabs label="History type">
        @foreach (\App\Filament\Pages\History::TABS as $tab => $label)
            <x-filament::tabs.item
                :active="$activeTab === $tab"
                wire:click="setTab('{{ $tab }}')"
                wire:key="history-tab-{{ $tab }}"
            >
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}

    @if (filled($nextCursor))
        <div class="flex justify-center">
            <x-filament::button
                color="gray"
                icon="heroicon-m-arrow-down"
                wire:click="loadMore"
                wire:target="loadMore"
            >
                Load more
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
