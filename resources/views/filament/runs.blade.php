<x-filament-panels::page>
    @if (! $pendingEnabled)
        <x-filament::section heading="Manual requests are disabled">
            The host must enable the pending backup feature before a backup can be requested here.
        </x-filament::section>
    @endif
    @if ($catalogAvailable)
        {{ $this->table }}
    @else
        <x-filament::section heading="Recovery catalog unavailable">The backup catalog tables are unavailable. After verifying the restored application, run <code>php artisan migrate</code>.</x-filament::section>
    @endif
</x-filament-panels::page>
