@extends('layouts.public')

@section('title', __('Private shared memory — :app', ['app' => config('app.name', 'Memoria')]))
@section('description', __('A memory shared with you through a private, unlisted link.'))
@section('robots', 'noindex,nofollow,noarchive')

@php
    $shareState = $state ?? 'available';
    $sharedContent = $content ?? $entry ?? $publication ?? null;
    $sharedAttachments = $sharedContent instanceof \App\Models\Entry && $sharedContent->relationLoaded('attachments')
        ? $sharedContent->attachments
        : collect();
@endphp

@section('content')
    <section class="mx-auto w-full max-w-4xl px-5 py-14 sm:px-8 sm:py-20" aria-labelledby="share-title">
        <div class="mb-7 flex flex-wrap items-center justify-between gap-4">
            <x-public.status-badge status="unlisted" />
            @if (! empty($share->expires_at))
                <span class="text-sm muted-copy">{{ __('Expires :date', ['date' => $share->expires_at->translatedFormat('F j, Y')]) }}</span>
            @endif
        </div>

        @if ($shareState === 'expired')
            <x-public.empty-state
                :title="__('This private link has expired')"
                :description="__('Ask the person who shared it to create a new link if they still want you to have access.')"
            />
        @elseif ($shareState === 'revoked')
            <x-public.empty-state
                :title="__('This private link was revoked')"
                :description="__('The owner has ended access to this shared memory.')"
            />
        @elseif ($shareState === 'locked')
            <div class="paper-surface mx-auto max-w-lg p-6 sm:p-9">
                <x-public.status-badge status="shared" :label="__('Password protected')" />
                <h1 id="share-title" class="editorial-title mt-6 text-3xl">{{ __('Enter the sharing password') }}</h1>
                <p class="muted-copy mt-3 leading-7">{{ __('This unlisted memory is protected by a password chosen by its owner.') }}</p>
                <form method="POST" action="{{ url()->current() }}" class="mt-7 grid gap-5">
                    @csrf
                    <div>
                        <label for="share-password" class="text-sm font-semibold">{{ __('Password') }}</label>
                        <input id="share-password" name="password" type="password" required autocomplete="current-password" class="form-field mt-2" @error('password') aria-describedby="share-password-error" aria-invalid="true" @enderror>
                        @error('password')
                            <p id="share-password-error" class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="button-primary">{{ __('Open shared memory') }}</button>
                </form>
            </div>
        @elseif ($sharedContent)
            <article class="paper-surface p-6 sm:p-10 lg:p-14">
                <header class="border-b hairline pb-8">
                    <p class="eyebrow">{{ __('Shared privately') }}</p>
                    <h1 id="share-title" class="editorial-title mt-3 text-4xl leading-tight sm:text-5xl">{{ $sharedContent->title }}</h1>
                    @if ($sharedContent instanceof \App\Models\Entry && $sharedContent->localOccurredAt())
                        <time class="muted-copy mt-4 block text-sm" datetime="{{ $sharedContent->localOccurredAt()->toAtomString() }}">{{ $sharedContent->localOccurredAt()->translatedFormat('F j, Y') }}</time>
                    @endif
                </header>
                <div class="editorial-copy mt-8">
                    {!! app(\App\Services\RichTextSanitizer::class)->sanitize($sharedContent->body) !!}
                </div>

                @if ($share->include_attachments && $sharedAttachments->isNotEmpty())
                    <section class="mt-10 border-t hairline pt-7" aria-labelledby="shared-attachments-title">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="eyebrow">{{ __('Included deliberately') }}</p>
                                <h2 id="shared-attachments-title" class="editorial-title mt-2 text-2xl">{{ __('Shared attachments') }}</h2>
                            </div>
                            <span class="privacy-seal">{{ __('Safety checked') }}</span>
                        </div>
                        <p class="muted-copy mt-3 text-sm leading-6">{{ __('Only files that completed the attachment safety check are available through this guarded link.') }}</p>
                        <ul class="mt-5 grid gap-3" role="list">
                            @foreach ($sharedAttachments as $attachment)
                                <li>
                                    <a class="button-secondary w-full justify-between sm:w-auto" href="{{ route('shares.attachments.show', ['token' => $token, 'attachment' => $attachment]) }}">
                                        <span>{{ $attachment->download_name ?: $attachment->original_name }}</span>
                                        <span class="text-xs font-normal muted-copy">{{ \Illuminate\Support\Number::fileSize((int) $attachment->size_bytes) }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </article>
            <p class="muted-copy mx-auto mt-5 max-w-2xl text-center text-xs leading-5">{{ __('This link is unlisted. Please respect the owner’s intended audience and do not redistribute it.') }}</p>
        @else
            <x-public.empty-state
                :title="__('This shared memory is unavailable')"
                :description="__('It may have been removed or made private again.')"
            />
        @endif
    </section>
@endsection
