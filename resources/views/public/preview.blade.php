@extends('layouts.public')

@section('title', __('Preview: :title', ['title' => $publication->title]))
@section('description', __('Private preview of a publication before it becomes public.'))
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    @php($topics = is_array($publication->topics) ? $publication->topics : [])
    <div class="sticky top-0 z-30 border-b {{ $previewConfirmed ? 'border-emerald-300 bg-emerald-50 text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100' : 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100' }} shadow-sm">
        <div class="mx-auto flex min-h-16 w-full max-w-7xl flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-8 lg:px-10">
            <div class="flex items-center gap-2 text-sm font-semibold">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6Z" /><circle cx="12" cy="12" r="2.5" /></svg>
                {{ $previewConfirmed ? __('Exact preview confirmed — not published') : __('Private preview — not published') }}
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ url('/app/publications/'.$publication->getRouteKey().'/edit') }}" class="button-secondary">{{ __('Back to edit') }}</a>
                @if (! $previewConfirmed)
                    <form method="POST" action="{{ route('app.publications.preview.store', $publication) }}" class="grid max-w-sm gap-2 text-xs">
                        @csrf
                        <span class="leading-5">{{ __('Inspect the full page, including every image and visible detail. Confirm only when this exact public version is safe to share.') }}</span>
                        <button type="submit" class="button-primary">{{ __('Confirm I inspected this exact preview') }}</button>
                    </form>
                @elseif (Route::has('app.publications.publish'))
                    <form method="POST" action="{{ route('app.publications.publish', $publication) }}" class="grid gap-2 text-xs">
                        @csrf
                        <input type="hidden" name="publish_to_website" value="1">
                        <input type="hidden" name="privacy_review_confirmed" value="1">
                        <input type="hidden" name="preview_confirmed" value="1">
                        <span class="max-w-xs leading-5">{{ __('The server recorded review and preview markers for this exact version. Any edit makes them invalid.') }}</span>
                        <button type="submit" class="button-primary">{{ __('Publish to my public profile') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <article class="mx-auto w-full max-w-[76rem] px-5 pb-20 pt-12 sm:px-8 sm:pb-28 sm:pt-18">
        <header class="mx-auto max-w-[48rem] text-center">
            <x-public.status-badge status="draft" />
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
        </header>
        @if ($publication->relationLoaded('media') && $publication->media->isNotEmpty())
            @php($featuredMedia = $publication->media->firstWhere('is_featured', true) ?? $publication->media->first())
            @if ($featuredMedia && str_starts_with($featuredMedia->mime_type, 'image/'))
                <figure class="mx-auto mt-12 max-w-[68rem]">
                    <x-public.publication-image
                        :media="$featuredMedia"
                        :alt="$featuredMedia->alt_text"
                        :preview="true"
                        usage="hero"
                        class="max-h-[44rem] w-full rounded-[0.65rem] border hairline object-cover shadow-[var(--shadow-paper)]"
                    />
                </figure>
            @endif
        @endif
        <div class="editorial-copy mx-auto mt-12 max-w-[70ch]">
            {!! app(\App\Services\RichTextSanitizer::class)->sanitize($publication->body) !!}
        </div>
    </article>
@endsection
