@extends('layouts.public')

@section('title', __('Privacy review: :title', ['title' => $publication->title]))
@section('description', __('A private review checklist before publishing.'))
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    <section class="mx-auto w-full max-w-3xl px-5 py-14 sm:px-8 sm:py-20" aria-labelledby="review-title">
        <x-public.status-badge status="draft" :label="__('Private review')" />
        <p class="eyebrow mt-7">{{ __('Share deliberately') }}</p>
        <h1 id="review-title" class="editorial-title mt-3 text-4xl leading-tight sm:text-5xl">{{ __('Privacy review') }}</h1>
        <p class="muted-copy mt-5 max-w-2xl leading-7">{{ __('Automated prompts are incomplete. Read the entire public version and consider names, locations, dates, health, work, relationships, and anyone else’s story before publishing.') }}</p>

        <div class="paper-surface mt-8 p-6 sm:p-8">
            <h2 class="editorial-title text-2xl">{{ $publication->title }}</h2>
            @if (empty($warnings))
                <div class="mt-6 flex items-start gap-3 text-sm leading-6 text-emerald-800 dark:text-emerald-200">
                    <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6" /></svg>
                    <p>{{ __('No automated warnings were found. This is not a guarantee that the story is safe to share.') }}</p>
                </div>
            @else
                <ul class="mt-6 grid gap-3" role="list">
                    @foreach ($warnings as $warning)
                        <li class="rounded-[0.5rem] border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                            {{ is_array($warning) ? ($warning['message'] ?? json_encode($warning)) : $warning }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('app.publications.privacy-review.store', $publication) }}">
                @csrf
                <button type="submit" class="button-primary">{{ __('Confirm review and open exact preview') }}</button>
            </form>
            <a href="{{ \App\Filament\App\Resources\PublicationResource::getUrl('edit', ['record' => $publication]) }}" class="button-secondary">{{ __('Back to edit') }}</a>
        </div>
    </section>
@endsection
