<x-filament-panels::page>
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-primary-700 dark:text-primary-300">{{ __('Private calendar') }}</p>
            <h2 class="mt-1 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold">{{ $this->currentMonth()->translatedFormat('F Y') }}</h2>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousMonth" class="fi-btn flex size-11 items-center justify-center rounded-lg border border-gray-300 dark:border-gray-700" aria-label="{{ __('Previous month') }}"><x-filament::icon icon="heroicon-o-chevron-left" class="size-5" /></button>
            <button type="button" wire:click="goToToday" class="fi-btn min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-semibold dark:border-gray-700">{{ __('Today') }}</button>
            <button type="button" wire:click="nextMonth" class="fi-btn flex size-11 items-center justify-center rounded-lg border border-gray-300 dark:border-gray-700" aria-label="{{ __('Next month') }}"><x-filament::icon icon="heroicon-o-chevron-right" class="size-5" /></button>
        </div>
    </div>

    @if ($this->isCapped())
        <div class="rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm leading-6 text-warning-900 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-100" role="status">
            <p class="font-semibold">{{ trans_choice(':count memory is not expanded here|:count memories are not expanded here', $this->hiddenEntryCount(), ['count' => $this->hiddenEntryCount()]) }}</p>
            <p class="mt-1">{{ __('Every day total below is complete. The calendar limits detailed cards to keep this busy month responsive.') }}</p>
            <a href="{{ $this->searchUrl() }}" class="mt-2 inline-flex font-semibold underline underline-offset-4">{{ __('Open every memory from this month in search') }}</a>
        </div>
    @endif

    <div class="hidden overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 md:block">
        <div class="grid grid-cols-7 border-b border-gray-200 text-center text-xs font-semibold uppercase tracking-[0.08em] text-gray-500 dark:border-gray-700 dark:text-gray-400">
            @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $weekday)
                <div class="px-2 py-3">{{ $weekday }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-7">
            @foreach ($this->days as $day)
                @php($dayEntries = $this->entries->filter(fn ($entry) => $entry->occurred_on?->isSameDay($day)))
                @php($dayCount = (int) ($this->dayCounts->get($day->toDateString()) ?? 0))
                @php($displayedDayCount = min(3, $dayEntries->count()))
                <section class="min-h-32 border-b border-e border-gray-100 p-2 dark:border-gray-800 {{ $day->month !== $this->currentMonth()->month ? 'bg-gray-50/70 text-gray-600 dark:bg-gray-950/40 dark:text-gray-300' : '' }}" wire:key="calendar-day-{{ $day->format('Y-m-d') }}" aria-label="{{ $day->translatedFormat('F j, Y') }}">
                    <div class="flex items-center justify-between gap-2">
                        <time class="flex size-8 items-center justify-center rounded-md text-sm font-semibold {{ $day->isToday() ? 'bg-primary-800 text-white' : '' }}" datetime="{{ $day->toDateString() }}">{{ $day->day }}</time>
                        @if ($dayCount > 0)
                            <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400" aria-label="{{ trans_choice(':count memory|:count memories', $dayCount, ['count' => $dayCount]) }}">{{ $dayCount }}</span>
                        @endif
                    </div>
                    <div class="mt-2 grid gap-1.5">
                        @foreach ($dayEntries->take(3) as $entry)
                            <a href="{{ \App\Filament\App\Resources\EntryResource::getUrl('edit', ['record' => $entry]) }}" class="truncate rounded-md border border-primary-200 bg-primary-50 px-2 py-1.5 text-xs font-medium text-primary-800 hover:bg-primary-100 dark:border-primary-800 dark:bg-primary-950 dark:text-primary-200">
                                {{ $entry->title ?: __('Untitled') }}
                            </a>
                        @endforeach
                        @if ($dayCount > $displayedDayCount)
                            <a href="{{ $this->searchUrl($day) }}" class="text-xs font-semibold text-primary-700 underline-offset-2 hover:underline dark:text-primary-300">
                                {{ __('+:count more', ['count' => $dayCount - $displayedDayCount]) }}
                            </a>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 md:hidden">
        @forelse ($this->entries as $entry)
            <x-filament.app.components.memory-card :entry="$entry" wire:key="mobile-calendar-{{ $entry->getKey() }}" />
        @empty
            <div class="rounded-lg border border-gray-200 bg-white px-5 py-10 text-center dark:border-gray-700 dark:bg-gray-900">
                <p class="font-semibold">{{ __('No memories this month') }}</p>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Move to another month or write a new memory.') }}</p>
            </div>
        @endforelse
        @if ($this->isCapped())
            <a href="{{ $this->searchUrl() }}" class="fi-btn inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 text-sm font-semibold dark:border-gray-700">
                {{ trans_choice('Open :count more memory|Open :count more memories', $this->hiddenEntryCount(), ['count' => $this->hiddenEntryCount()]) }}
            </a>
        @endif
    </div>
</x-filament-panels::page>
