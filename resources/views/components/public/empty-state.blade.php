@props([
    'title',
    'description',
    'actionUrl' => null,
    'actionLabel' => null,
])

<section {{ $attributes->class('paper-surface px-6 py-12 text-center sm:px-10') }} aria-labelledby="empty-state-title">
    <div class="mx-auto flex size-12 items-center justify-center rounded-[0.6rem] border hairline bg-[var(--page-bg)] text-[var(--accent)]">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M5 4h11a3 3 0 0 1 3 3v14H8a3 3 0 0 1-3-3V4Z" />
            <path stroke-linecap="round" d="M8.5 9H15M8.5 13H13" />
        </svg>
    </div>
    <h2 id="empty-state-title" class="editorial-title mt-5 text-2xl">{{ $title }}</h2>
    <p class="muted-copy mx-auto mt-2 max-w-md text-sm leading-6">{{ $description }}</p>
    @if ($actionUrl && $actionLabel)
        <a href="{{ $actionUrl }}" class="button-primary mt-6">{{ $actionLabel }}</a>
    @endif
</section>

