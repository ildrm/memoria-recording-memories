<x-filament-panels::page>
    <div class="mb-2 max-w-3xl">
        <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ __('These are individual memories other members deliberately shared with your account. Access is view-only: you cannot edit, publish, reshare, or include them in diary search.') }}
        </p>
        <label for="shared-memory-search" class="sr-only">{{ __('Search shared memories') }}</label>
        <div class="relative mt-5 max-w-xl">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="pointer-events-none absolute start-3 top-3.5 size-5 text-gray-400" />
            <input
                id="shared-memory-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Search by memory title or sharer') }}"
                class="fi-input min-h-12 w-full rounded-lg border border-gray-300 bg-white ps-10 pe-4 text-sm text-gray-950 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
            >
        </div>
    </div>

    @if ($this->shares->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white px-6 py-14 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <x-filament::icon icon="heroicon-o-users" class="mx-auto size-10 text-primary-700 dark:text-primary-300" />
            <h2 class="mt-5 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold text-gray-950 dark:text-white">{{ __('Nothing has been shared with you') }}</h2>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('When another registered member grants you access to one memory, it will appear here until it expires or is revoked.') }}</p>
        </div>
    @else
        <div class="grid gap-6 xl:grid-cols-[minmax(18rem,24rem)_minmax(0,1fr)]">
            <section aria-labelledby="shared-memory-list-title">
                <h2 id="shared-memory-list-title" class="sr-only">{{ __('Shared memory list') }}</h2>
                <div class="grid gap-3">
                    @foreach ($this->shares as $share)
                        @continue(! $share->entry)
                        @php
                            $sharedOccurredAt = $share->entry->localOccurredAt();
                        @endphp
                        <button
                            type="button"
                            wire:key="shared-memory-{{ $share->getKey() }}"
                            wire:click="openMemory({{ $share->entry_id }})"
                            @class([
                                'w-full rounded-lg border bg-white p-4 text-start shadow-sm transition hover:border-primary-400 hover:bg-primary-50/30 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-primary-950/20',
                                'border-primary-500 ring-2 ring-primary-500/20' => $selectedEntryId === (int) $share->entry_id,
                                'border-gray-200 dark:border-gray-700' => $selectedEntryId !== (int) $share->entry_id,
                            ])
                            aria-pressed="{{ $selectedEntryId === (int) $share->entry_id ? 'true' : 'false' }}"
                        >
                            <span class="block font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-lg font-semibold text-gray-950 dark:text-white">{{ $share->entry->title ?: __('Untitled memory') }}</span>
                            <span class="mt-2 block text-sm text-gray-600 dark:text-gray-300">{{ __('Shared by :name', ['name' => $share->owner?->name ?? __('a member')]) }}</span>
                            <span class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                @if ($sharedOccurredAt)
                                    <time datetime="{{ $sharedOccurredAt->toAtomString() }}">{{ $sharedOccurredAt->translatedFormat('M j, Y') }}</time>
                                @endif
                                <span>{{ $share->include_attachments ? __('Clean attachments included') : __('Memory only') }}</span>
                                @if ($share->expires_at)
                                    <span>{{ __('Expires :date', ['date' => $share->expires_at->translatedFormat('M j, Y')]) }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>
                @if ($this->shares->hasPages())
                    <div class="mt-5">{{ $this->shares->onEachSide(1)->links() }}</div>
                @endif
            </section>

            <section aria-labelledby="shared-memory-title" aria-live="polite">
                <div wire:loading.flex wire:target="openMemory" class="min-h-64 items-center justify-center rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900" role="status">
                    <div class="text-center text-sm text-gray-600 dark:text-gray-300">
                        <x-filament::loading-indicator class="mx-auto mb-3 size-6" />
                        {{ __('Opening the memory…') }}
                    </div>
                </div>

                <div wire:loading.remove wire:target="openMemory">
                    @if ($this->selectedMemory)
                        @php
                            $selectedShare = $this->selectedMemory['share'];
                            $selectedEntry = $this->selectedMemory['entry'];
                            $selectedOccurredAt = $selectedEntry->localOccurredAt();
                        @endphp
                        <article class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-8">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <span class="inline-flex rounded-md border border-primary-200 bg-primary-50 px-2 py-1 text-xs font-semibold text-primary-800 dark:border-primary-800 dark:bg-primary-950 dark:text-primary-200">{{ __('View-only memory') }}</span>
                                    <h2 id="shared-memory-title" class="mt-4 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $selectedEntry->title ?: __('Untitled memory') }}</h2>
                                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Shared by :name', ['name' => $selectedShare->owner?->name ?? __('a member')]) }}</p>
                                </div>
                                <x-filament::button wire:click="closeMemory" color="gray" outlined icon="heroicon-o-x-mark" class="xl:hidden">
                                    {{ __('Close') }}
                                </x-filament::button>
                            </div>

                            <dl class="mt-6 flex flex-wrap gap-x-6 gap-y-3 border-y border-gray-200 py-4 text-sm dark:border-gray-700">
                                @if ($selectedOccurredAt)
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('When') }}</dt>
                                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $selectedOccurredAt->translatedFormat('F j, Y · H:i') }}</dd>
                                    </div>
                                @endif
                                @if ($selectedEntry->location_name)
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Place') }}</dt>
                                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $selectedEntry->location_name }}</dd>
                                    </div>
                                @endif
                                @if ($selectedEntry->mood || $selectedEntry->custom_mood)
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Mood') }}</dt>
                                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $selectedEntry->custom_mood ?: ($selectedEntry->mood instanceof \App\Enums\Mood ? $selectedEntry->mood->label() : str($selectedEntry->mood)->headline()) }}</dd>
                                    </div>
                                @endif
                            </dl>

                            <div class="prose prose-stone mt-8 max-w-none dark:prose-invert">
                                {!! app(\App\Services\RichTextSanitizer::class)->sanitize($selectedEntry->body) !!}
                            </div>

                            @if ($selectedShare->include_attachments && $selectedEntry->relationLoaded('attachments') && $selectedEntry->attachments->isNotEmpty())
                                <section class="mt-10 border-t border-gray-200 pt-6 dark:border-gray-700" aria-labelledby="shared-files-title">
                                    <h3 id="shared-files-title" class="font-semibold text-gray-950 dark:text-white">{{ __('Included attachments') }}</h3>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Only files that passed the safety check are available.') }}</p>
                                    <ul class="mt-4 grid gap-2">
                                        @foreach ($selectedEntry->attachments as $attachment)
                                            <li wire:key="registered-shared-attachment-{{ $attachment->getKey() }}">
                                                <a href="{{ route('attachments.download', $attachment) }}" class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-900 hover:border-primary-400 dark:border-gray-700 dark:text-gray-100 dark:hover:border-primary-500">
                                                    <x-filament::icon icon="heroicon-o-paper-clip" class="size-4" />
                                                    {{ $attachment->download_name }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endif

                            <p class="mt-8 rounded-lg bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-600 dark:bg-gray-950 dark:text-gray-300">
                                <x-filament::icon icon="heroicon-o-lock-closed" class="me-1 inline size-4 align-text-bottom" />
                                {{ __('This access is personal and view-only. The owner can revoke it, and an expiry may close it automatically.') }}
                            </p>
                        </article>
                    @else
                        <div class="flex min-h-64 items-center justify-center rounded-lg border border-dashed border-gray-300 bg-white px-6 text-center dark:border-gray-700 dark:bg-gray-900">
                            <div>
                                <x-filament::icon icon="heroicon-o-book-open" class="mx-auto size-9 text-gray-400" />
                                <h2 id="shared-memory-title" class="mt-4 font-semibold text-gray-950 dark:text-white">{{ __('Choose a shared memory') }}</h2>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Its contents stay out of your own timeline and search.') }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    @endif
</x-filament-panels::page>
