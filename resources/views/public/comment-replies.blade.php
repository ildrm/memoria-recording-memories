@extends('layouts.public')

@section('title', __('Replies to a response — :title', ['title' => $publication->title]))
@section('description', __('A paginated public conversation attached to :title.', ['title' => $publication->title]))
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    <main class="mx-auto w-full max-w-[52rem] px-5 py-14 sm:px-8 sm:py-20" aria-labelledby="replies-title">
        <a href="{{ route('publications.show', ['username' => $profile->username, 'publicationSlug' => $publication->slug]) }}#comment-{{ $parentComment->getKey() }}" class="button-quiet">
            <span aria-hidden="true">&larr;</span>
            {{ __('Back to the story') }}
        </a>

        <header class="mt-8">
            <p class="eyebrow">{{ __('Conversation on :title', ['title' => $publication->title]) }}</p>
            <h1 id="replies-title" class="editorial-title mt-3 text-4xl sm:text-5xl">{{ __('Replies') }}</h1>
        </header>

        <article class="paper-surface mt-8 p-5 sm:p-7" aria-label="{{ __('Original response') }}">
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <span class="font-semibold">{{ $parentComment->author?->name ?? __('Reader') }}</span>
                <time class="muted-copy" datetime="{{ $parentComment->created_at?->toAtomString() }}">{{ $parentComment->created_at?->diffForHumans() }}</time>
            </div>
            <p class="mt-3 whitespace-pre-line leading-7">{{ $parentComment->body }}</p>
        </article>

        <ol class="mt-7 grid gap-4 border-s-2 border-[var(--border)] ps-4 sm:ps-6" aria-label="{{ __('All replies') }}">
            @foreach ($replies as $reply)
                <li id="comment-{{ $reply->getKey() }}" class="paper-surface p-5 sm:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <span class="font-semibold">{{ $reply->author?->name ?? __('Reader') }}</span>
                        <time class="muted-copy" datetime="{{ $reply->created_at?->toAtomString() }}">{{ $reply->created_at?->diffForHumans() }}</time>
                    </div>
                    <p class="mt-3 whitespace-pre-line leading-7">{{ $reply->body }}</p>
                </li>
            @endforeach
        </ol>

        @if ($replies->hasPages())
            <nav class="mt-7" aria-label="{{ __('Reply pages') }}">
                {{ $replies->withQueryString()->fragment('replies-title')->onEachSide(1)->links() }}
            </nav>
        @endif
    </main>
@endsection
