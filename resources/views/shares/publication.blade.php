@extends('layouts.public')

@section('title', $publication->title . ' — ' . config('app.name', 'Memoria'))
@section('description', $publication->excerpt ?: __('A publication shared through a private, unlisted link.'))
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    <article class="mx-auto w-full max-w-[76rem] px-5 py-14 sm:px-8 sm:py-20" aria-labelledby="shared-publication-title">
        <header class="mx-auto max-w-[48rem] text-center">
            <x-public.status-badge status="unlisted" />
            <h1 id="shared-publication-title" class="editorial-title mt-7 text-[clamp(2.8rem,7vw,5.7rem)] leading-[1.02]">{{ $publication->title }}</h1>
            @if ($publication->excerpt)
                <p class="muted-copy mx-auto mt-6 max-w-[42rem] text-lg leading-8 sm:text-xl">{{ $publication->excerpt }}</p>
            @endif
        </header>
        <div class="editorial-copy mx-auto mt-12 max-w-[70ch]">{!! app(\App\Services\RichTextSanitizer::class)->sanitize($publication->body) !!}</div>
        <p class="muted-copy mx-auto mt-12 max-w-2xl border-t hairline pt-6 text-center text-xs leading-5">{{ __('This is a private, unlisted share. It is not part of the public profile unless separately published.') }}</p>
    </article>
@endsection
