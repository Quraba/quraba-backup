<x-filament-panels::page>
    @if (! $pendingEnabled)
        <x-filament::section heading="Manual requests are disabled">
            The host must enable the pending backup feature before a backup can be requested here.
        </x-filament::section>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
