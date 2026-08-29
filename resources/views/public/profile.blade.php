@extends('layouts.public')

@php
    $profileOwner = $profile->user ?? $user ?? null;
    $displayName = $profile->display_name ?? $profileOwner?->name ?? __('Writer');
    $biography = $profile->biography ?? $profile->bio ?? null;
    $avatarUrl = filled($profile->avatar_path) ? route('profiles.images.show', ['username' => $profile->username, 'kind' => 'avatar']) : null;
    $coverUrl = filled($profile->cover_image_path) ? route('profiles.images.show', ['username' => $profile->username, 'kind' => 'cover']) : null;
@endphp

@section('title', $displayName . ' — ' . config('app.name', 'Memoria'))
@section('description', $biography ? str($biography)->limit(155) : __('Public stories by :name.', ['name' => $displayName]))
@section('canonical', url()->current())
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    <section class="mx-auto w-full max-w-5xl px-5 py-14 sm:px-8 sm:py-20" aria-labelledby="profile-title">
        @if ($coverUrl)
            <img src="{{ $coverUrl }}" alt="" class="mb-8 aspect-[3/1] w-full rounded-[0.65rem] border hairline object-cover" role="presentation">
        @endif
        <header class="grid gap-6 border-b hairline pb-10 sm:grid-cols-[5rem_1fr] sm:items-start sm:gap-8">
            @if ($avatarUrl)
                <img src="{{ $avatarUrl }}" alt="{{ __('Portrait of :name', ['name' => $displayName]) }}" class="size-20 rounded-[0.65rem] border hairline object-cover">
            @else
                <div class="flex size-20 items-center justify-center rounded-[0.65rem] border hairline bg-[var(--surface)] editorial-title text-3xl" aria-hidden="true">
                    {{ str($displayName)->substr(0, 1)->upper() }}
                </div>
            @endif
            <div>
                <p class="eyebrow">{{ __('Public journal') }}</p>
                <h1 id="profile-title" class="editorial-title mt-2 text-4xl sm:text-5xl">{{ $displayName }}</h1>
                @if ($biography)
                    <p class="muted-copy mt-4 max-w-2xl leading-7">{{ $biography }}</p>
                @endif
            </div>
        </header>

        <div class="pt-8">
            <div class="flex items-end justify-between gap-5">
                <div>
                    <p class="eyebrow">{{ __('Published stories') }}</p>
                    <h2 class="editorial-title mt-2 text-3xl">{{ __('The public archive') }}</h2>
                </div>
            </div>

            @if ($publications->isEmpty())
                <x-public.empty-state
                    class="mt-8"
                    :title="__('No public stories yet')"
                    :description="__('This writer has not published anything publicly.')"
                />
            @else
                <div class="mt-5" aria-live="polite">
                    @foreach ($publications as $publication)
                        <x-public.publication-card :publication="$publication" :username="$profile->username" :wire:key="'publication-'.$publication->getKey()" />
                    @endforeach
                </div>
                <div class="mt-10">{{ $publications->links() }}</div>
            @endif
        </div>
    </section>
@endsection
