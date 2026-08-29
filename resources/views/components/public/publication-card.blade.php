@props(['publication', 'username' => null])

@php
    $publicationUrl = $username
        ? route('publications.show', ['username' => $username, 'publicationSlug' => $publication->slug])
        : '#';
    $featuredMedia = $publication->relationLoaded('featuredMedia') ? $publication->featuredMedia : null;
    $featuredDimensions = is_array($featuredMedia?->metadata) ? $featuredMedia->metadata : [];
    $isPlaceholderMedia = isset($featuredDimensions['width'], $featuredDimensions['height'])
        && (int) $featuredDimensions['width'] <= 1
        && (int) $featuredDimensions['height'] <= 1;
@endphp

<article {{ $attributes->class('publication-card') }}>
    @if ($featuredMedia && str_starts_with($featuredMedia->mime_type, 'image/') && ! $isPlaceholderMedia)
        <a href="{{ $publicationUrl }}" tabindex="-1" aria-hidden="true">
            <x-public.publication-image
                :media="$featuredMedia"
                alt=""
                usage="card"
                class="mb-2 aspect-[16/7] w-full rounded-[0.55rem] border hairline object-cover"
            />
        </a>
    @endif
    <div class="flex flex-wrap items-center gap-3 text-xs muted-copy">
        <time datetime="{{ optional($publication->published_at)->toAtomString() }}">
            {{ optional($publication->published_at)->translatedFormat('F j, Y') ?? __('Recently published') }}
        </time>
        <span aria-hidden="true">&middot;</span>
        <x-public.status-badge status="published" />
    </div>
    <div>
        <h2 class="editorial-title text-2xl sm:text-3xl">
            <a href="{{ $publicationUrl }}" class="decoration-[var(--ribbon)] decoration-1 underline-offset-4 hover:underline">
                {{ $publication->title }}
            </a>
        </h2>
        @if ($publication->excerpt)
            <p class="muted-copy mt-3 max-w-2xl leading-7">{{ $publication->excerpt }}</p>
        @endif
    </div>
    <a href="{{ $publicationUrl }}" class="inline-flex min-h-11 w-fit items-center gap-2 text-sm font-semibold text-[var(--accent-strong)]">
        {{ __('Read story') }}
        <span aria-hidden="true">&rarr;</span>
    </a>
</article>
