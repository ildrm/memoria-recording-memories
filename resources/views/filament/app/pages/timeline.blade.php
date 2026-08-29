<x-filament-panels::page>
    @if ($this->entries->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white px-6 py-14 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <x-filament::icon icon="heroicon-o-queue-list" class="mx-auto size-10 text-primary-700 dark:text-primary-300" />
            <h2 class="mt-5 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->emptyHeading() }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $this->emptyDescription() }}</p>
            <a href="{{ \App\Filament\App\Resources\EntryResource::getUrl('create') }}" class="fi-btn mt-6 inline-flex min-h-11 items-center rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white">{{ __('Write a memory') }}</a>
        </div>
    @else
        <div class="memoria-ribbon-list grid gap-5">
            @foreach ($this->entries as $entry)
                <div class="relative" wire:key="timeline-{{ $entry->getKey() }}">
                    <span class="absolute -start-[1.72rem] top-6 size-3 rounded-full border-2 border-[var(--memoria-ribbon)] bg-[var(--memoria-paper)]" aria-hidden="true"></span>
                    <x-filament.app.components.memory-card :entry="$entry" />
                </div>
            @endforeach
        </div>
        <div class="mt-6">{{ $this->entries->links() }}</div>
    @endif
</x-filament-panels::page>

