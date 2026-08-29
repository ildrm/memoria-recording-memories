<x-filament-panels::page>
    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900" aria-labelledby="memory-search-heading">
        <h2 id="memory-search-heading" class="sr-only">{{ __('Search filters') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="memory-search" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Words to find') }}</label>
                <div class="mt-2 flex min-h-11 items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 focus-within:ring-2 focus-within:ring-primary-600 dark:border-gray-700 dark:bg-gray-950">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="size-5 text-gray-400" />
                    <input id="memory-search" type="search" wire:model.live.debounce.400ms="query" class="min-w-0 flex-1 border-0 bg-transparent py-2 outline-none" placeholder="{{ __('Title, words, or place…') }}">
                </div>
            </div>
            <div>
                <label for="journal-filter" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Journal') }}</label>
                <select id="journal-filter" wire:model.live="journal" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
                    <option value="">{{ __('All journals') }}</option>
                    @foreach ($this->journals as $journalOption)
                        <option value="{{ $journalOption->getKey() }}" wire:key="journal-option-{{ $journalOption->getKey() }}">{{ $journalOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tag-filter" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Tag') }}</label>
                <select id="tag-filter" wire:model.live="tag" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
                    <option value="">{{ __('All tags') }}</option>
                    @foreach ($this->tags as $tagOption)
                        <option value="{{ $tagOption->getKey() }}" wire:key="tag-option-{{ $tagOption->getKey() }}">{{ $tagOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="person-filter" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Person') }}</label>
                <select id="person-filter" wire:model.live="person" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
                    <option value="">{{ __('Anyone') }}</option>
                    @foreach ($this->people as $personOption)
                        <option value="{{ $personOption->getKey() }}" wire:key="person-option-{{ $personOption->getKey() }}">{{ $personOption->display_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="mood-filter" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Mood') }}</label>
                <select id="mood-filter" wire:model.live="mood" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
                    <option value="">{{ __('Any mood') }}</option>
                    @foreach (\App\Enums\Mood::cases() as $moodOption)
                        <option value="{{ $moodOption->value }}">{{ $moodOption->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="media-filter" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Attachment type') }}</label>
                <select id="media-filter" wire:model.live="attachmentMediaType" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
                    <option value="">{{ __('Any attachment') }}</option>
                    @foreach (\App\Enums\AttachmentMediaType::cases() as $mediaOption)
                        <option value="{{ $mediaOption->value }}">{{ $mediaOption->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="date-from-filter" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('From date') }}</label>
                <input id="date-from-filter" type="date" wire:model.live="dateFrom" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
            </div>
            <div>
                <label for="date-to-filter" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('To date') }}</label>
                <input id="date-to-filter" type="date" wire:model.live="dateTo" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
            </div>
            <div>
                <label for="archived-filter" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Archived memories') }}</label>
                <select id="archived-filter" wire:model.live="archivedState" class="mt-2 min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
                    <option value="exclude">{{ __('Exclude archived') }}</option>
                    <option value="include">{{ __('Include archived') }}</option>
                    <option value="only">{{ __('Only archived') }}</option>
                </select>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex min-h-11 items-center gap-2 text-sm font-medium">
                    <input type="checkbox" wire:model.live="favoritesOnly" class="size-4 rounded border-gray-300 text-primary-600">
                    {{ __('Favorites only') }}
                </label>
                @if ($this->hasActiveFilters())
                    <button type="button" wire:click="clearFilters" class="fi-btn min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-semibold dark:border-gray-700">{{ __('Reset all') }}</button>
                @endif
            </div>
        </div>

        @if ($this->hasActiveFilters())
            @php
                $journalLabel = $journal !== '' ? $this->journals->firstWhere('id', (int) $journal)?->name : null;
                $tagLabel = $tag !== '' ? $this->tags->firstWhere('id', (int) $tag)?->name : null;
                $personLabel = $person !== '' ? $this->people->firstWhere('id', (int) $person)?->display_name : null;
            @endphp
            <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-gray-200 pt-4 text-xs dark:border-gray-700" aria-live="polite">
                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ __('Active filters:') }}</span>
                @if (trim($query) !== '') <span class="rounded-md bg-primary-50 px-2 py-1 text-primary-800 dark:bg-primary-950 dark:text-primary-200">{{ __('Words: :value', ['value' => str($query)->limit(30)]) }}</span> @endif
                @if ($journalLabel) <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ __('Journal: :value', ['value' => $journalLabel]) }}</span> @endif
                @if ($tagLabel) <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ __('Tag: :value', ['value' => $tagLabel]) }}</span> @endif
                @if ($personLabel) <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ __('Person: :value', ['value' => $personLabel]) }}</span> @endif
                @if ($mood !== '') <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ __('Mood: :value', ['value' => \App\Enums\Mood::from($mood)->label()]) }}</span> @endif
                @if ($dateFrom !== '') <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ __('From :date', ['date' => $dateFrom]) }}</span> @endif
                @if ($dateTo !== '') <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ __('Through :date', ['date' => $dateTo]) }}</span> @endif
                @if ($archivedState !== 'exclude') <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ $archivedState === 'only' ? __('Archived only') : __('Archived included') }}</span> @endif
                @if ($attachmentMediaType !== '') <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ __('Attachment: :value', ['value' => \App\Enums\AttachmentMediaType::from($attachmentMediaType)->label()]) }}</span> @endif
                @if ($favoritesOnly) <span class="rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ __('Favorites') }}</span> @endif
            </div>
        @endif
        <div wire:loading class="mt-4 text-sm text-gray-600 dark:text-gray-300" role="status">{{ __('Searching your private memories…') }}</div>
    </section>

    @if ($this->entries->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white px-6 py-12 text-center dark:border-gray-700 dark:bg-gray-900">
            <h2 class="font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold">{{ __('No memories match') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Try fewer words or reset the active filters.') }}</p>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2" aria-live="polite">
            @foreach ($this->entries as $entry)
                <x-filament.app.components.memory-card :entry="$entry" wire:key="search-memory-{{ $entry->getKey() }}" />
            @endforeach
        </div>
        <div>{{ $this->entries->onEachSide(1)->links() }}</div>
    @endif
</x-filament-panels::page>
