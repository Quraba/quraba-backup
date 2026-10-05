<x-filament-panels::page>
    @include('quraba-backup::filament.partials.styles')
    @php($ui = \Quraba\Backup\Filament\Ui::class)
    <div class="qb-ui space-y-5">
        @if (! $pendingEnabled)
            <x-filament::section :heading="$ui::text('sections.manual_disabled')"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.panel_disabled') }}</p></x-filament::section>
        @endif
        @if ($catalogAvailable)
            {{ $this->table }}
        @else
            <x-filament::section :heading="$ui::text('sections.catalog_unavailable')"><p class="text-sm text-warning-600 dark:text-warning-400">{{ $ui::text('notices.catalog_unavailable') }}</p></x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
