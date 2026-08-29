<x-filament-panels::page>
    <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm leading-6 text-warning-900 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-100">
        <div class="flex items-start gap-3">
            <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 size-5 shrink-0" />
            <p>{{ __('Deleting a private source memory does not automatically remove an independent public publication. Review Publications separately before permanent deletion.') }}</p>
        </div>
    </div>

    @if ($this->entries->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-gray-900">
            <x-filament::icon icon="heroicon-o-trash" class="mx-auto size-10 text-gray-400" />
            <h2 class="mt-5 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold">{{ __('Trash is empty') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Deleted memories will remain here until restored or permanently removed.') }}</p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach ($this->entries as $entry)
                <article class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900" wire:key="trash-{{ $entry->getKey() }}">
                    <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Deleted :time', ['time' => $entry->deleted_at?->diffForHumans()]) }}</p>
                            <h2 class="mt-1 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-xl font-semibold">{{ $entry->title ?: __('Untitled memory') }}</h2>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="restore({{ $entry->getKey() }})" class="fi-btn min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-semibold dark:border-gray-700">{{ __('Restore') }}</button>
                            <button type="button" wire:click="deletePermanently({{ $entry->getKey() }})" wire:confirm="{{ __('Permanently delete this private memory and unreferenced files? This cannot be undone.') }}" class="fi-btn min-h-11 rounded-lg bg-danger-600 px-4 text-sm font-semibold text-white">{{ __('Delete permanently') }}</button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
        <div>{{ $this->entries->links() }}</div>
    @endif
</x-filament-panels::page>
