@props(['entry', 'showActions' => true])
@php($localOccurredAt = $entry->localOccurredAt())

<article {{ $attributes->class('rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900') }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-primary-700 dark:text-primary-300">
                {{ $localOccurredAt?->translatedFormat('F j, Y') ?? __('Date not set') }}
            </p>
            <h3 class="mt-2 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-xl font-semibold tracking-[-0.02em] text-gray-950 dark:text-white">
                {{ $entry->title ?: __('Untitled memory') }}
            </h3>
        </div>
        <span class="inline-flex min-h-7 items-center gap-1.5 rounded-md border border-success-300 bg-success-50 px-2.5 text-xs font-semibold text-success-800 dark:border-success-700 dark:bg-success-950 dark:text-success-200">
            <x-filament::icon icon="heroicon-o-lock-closed" class="size-3.5" />
            {{ __('Only me') }}
        </span>
    </div>

    @if ($entry->body)
        <p class="mt-3 line-clamp-3 font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-base leading-7 text-gray-600 dark:text-gray-300">
            {{ str(strip_tags($entry->body))->squish()->limit(190) }}
        </p>
    @endif

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
        <span>{{ $entry->journal?->name ?? __('No journal') }}</span>
        @if ($showActions)
            <a href="{{ \App\Filament\App\Resources\EntryResource::getUrl('edit', ['record' => $entry]) }}" class="inline-flex min-h-10 items-center gap-1.5 font-semibold text-primary-700 hover:underline dark:text-primary-300">
                {{ __('Open memory') }}
                <span aria-hidden="true">&rarr;</span>
            </a>
        @endif
    </div>
</article>
