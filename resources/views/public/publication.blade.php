@extends('layouts.public')

@php
    $author = $publication->owner ?? $publication->user ?? null;
    $authorName = $author?->profile?->display_name ?? $author?->name ?? __('Anonymous writer');
    $publishedDate = $publication->published_at ?? $publication->created_at;
    $topics = is_array($publication->topics) ? $publication->topics : [];
    $safeBody = app(\App\Services\RichTextSanitizer::class)->sanitize($publication->body);
    $metaDescription = $publication->excerpt ?: str(strip_tags($safeBody))->squish()->limit(155);
@endphp

@section('title', $publication->title . ' — ' . config('app.name', 'Memoria'))
@section('description', $metaDescription)
@section('canonical', url()->current())
@section('robots', $publication->search_engine_indexing ? 'index,follow' : 'noindex,nofollow,noarchive')

@push('head')
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $publication->title }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ url()->current() }}">
@endpush

@section('content')
    <article class="mx-auto w-full max-w-[76rem] px-5 pb-20 pt-12 sm:px-8 sm:pb-28 sm:pt-18">
        <header class="mx-auto max-w-[48rem] text-center">
            <x-public.status-badge status="published" />
            <h1 class="editorial-title mt-7 text-[clamp(2.8rem,7vw,5.7rem)] leading-[1.02]">{{ $publication->title }}</h1>
            @if ($publication->excerpt)
                <p class="muted-copy mx-auto mt-6 max-w-[42rem] text-lg leading-8 sm:text-xl">{{ $publication->excerpt }}</p>
            @endif
            @if ($topics !== [])
                <ul class="mt-6 flex flex-wrap justify-center gap-2" aria-label="{{ __('Story topics') }}">
                    @foreach ($topics as $topic)
                        <li><span class="privacy-seal">#{{ $topic }}</span></li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-8 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-sm muted-copy">
                <span>{{ __('By :name', ['name' => $authorName]) }}</span>
                <span aria-hidden="true">&middot;</span>
                <time datetime="{{ optional($publishedDate)->toAtomString() }}">{{ optional($publishedDate)->translatedFormat('F j, Y') }}</time>
                @if (! empty($publication->reading_time))
                    <span aria-hidden="true">&middot;</span>
                    <span>{{ trans_choice(':count minute read|:count minutes read', $publication->reading_time, ['count' => $publication->reading_time]) }}</span>
                @endif
            </div>
        </header>

        @php
            $featuredMedia = $publication->relationLoaded('media')
                ? ($publication->media->firstWhere('is_featured', true) ?? $publication->media->first())
                : null;
            $featuredDimensions = is_array($featuredMedia?->metadata) ? $featuredMedia->metadata : [];
            $isPlaceholderMedia = isset($featuredDimensions['width'], $featuredDimensions['height'])
                && (int) $featuredDimensions['width'] <= 1
                && (int) $featuredDimensions['height'] <= 1;
        @endphp
        @if ($featuredMedia && str_starts_with($featuredMedia->mime_type, 'image/') && ! $isPlaceholderMedia)
            <figure class="mx-auto mt-12 max-w-[68rem]">
                <x-public.publication-image
                    :media="$featuredMedia"
                    :alt="$featuredMedia->alt_text ?: __('Featured image for :title', ['title' => $publication->title])"
                    usage="hero"
                    class="max-h-[44rem] w-full rounded-[0.65rem] border hairline object-cover shadow-[var(--shadow-paper)]"
                />
            </figure>
        @endif

        <div class="editorial-copy mx-auto mt-12 max-w-[70ch]">
            {!! $safeBody !!}
        </div>

        <aside class="paper-surface mx-auto mt-14 grid max-w-[48rem] gap-6 p-6 sm:grid-cols-[1fr_auto] sm:items-center sm:p-8" aria-label="{{ __('About the author') }}">
            <div>
                <p class="eyebrow">{{ __('Written by') }}</p>
                <h2 class="editorial-title mt-2 text-2xl">{{ $authorName }}</h2>
                @if ($author?->profile?->biography)
                    <p class="muted-copy mt-2 leading-7">{{ $author->profile->biography }}</p>
                @endif
            </div>
            @if ($author?->profile?->username && Route::has('profiles.show'))
                <a href="{{ route('profiles.show', $author->profile->username) }}" class="button-secondary">{{ __('More stories') }}</a>
            @endif
        </aside>

        <div class="mx-auto mt-8 flex max-w-[48rem] justify-center">
            <button type="button" class="button-quiet" data-copy-url="{{ url()->current() }}">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="m9 15 6-6m-8.5 9.5-1 1a3.5 3.5 0 0 1-5-5l4-4a3.5 3.5 0 0 1 5 0m8 5 1-1a3.5 3.5 0 0 0-5-5l-1 1" /></svg>
                {{ __('Copy story link') }}
            </button>
        </div>

        @include('public.partials.publication-interactions')
    </article>
@endsection
