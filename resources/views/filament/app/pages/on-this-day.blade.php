<x-filament-panels::page>
    @if (! $this->isEnabled())
        <div class="rounded-lg border border-gray-200 bg-white px-6 py-14 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mx-auto flex size-12 items-center justify-center rounded-lg border border-gray-200 text-primary-700 dark:border-gray-700 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-sparkles" class="size-6" />
            </div>
            <h2 class="mt-5 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold text-gray-950 dark:text-white">{{ __('On This Day is turned off') }}</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('No earlier memories are shown here while this preference is off. Your diary remains unchanged.') }}</p>
            <a href="{{ \App\Filament\App\Pages\Settings::getUrl() }}" class="fi-btn mt-6 inline-flex min-h-11 items-center rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white">
                {{ __('Open memory settings') }}
            </a>
        </div>
    @elseif ($this->entries->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white px-6 py-14 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mx-auto flex size-12 items-center justify-center rounded-lg border border-gray-200 text-primary-700 dark:border-gray-700 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-book-open" class="size-6" />
            </div>
            <h2 class="mt-5 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->emptyHeading() }}</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $this->emptyDescription() }}</p>
            <a href="{{ \App\Filament\App\Resources\EntryResource::getUrl('create') }}" class="fi-btn mt-6 inline-flex min-h-11 items-center rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white">
                {{ __('Write a memory') }}
            </a>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($this->entries as $entry)
                <x-filament.app.components.memory-card :entry="$entry" wire:key="on-this-day-memory-{{ $entry->getKey() }}" />
            @endforeach
        </div>
        <div class="mt-6">{{ $this->entries->links() }}</div>
    @endif
</x-filament-panels::page>
