@extends('layouts.public')

@section('title', __('Private shared memory — :app', ['app' => config('app.name', 'Memoria')]))
@section('description', __('A memory shared through a private, unlisted link.'))
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    @php
        $sharedAttachments = $entry->relationLoaded('attachments')
            ? $entry->attachments->filter(fn ($attachment) => $attachment->scan_status === \App\Enums\AttachmentScanStatus::Clean)
            : collect();
    @endphp
    <section class="mx-auto w-full max-w-4xl px-5 py-14 sm:px-8 sm:py-20" aria-labelledby="shared-entry-title">
        <div class="mb-7 flex flex-wrap items-center justify-between gap-4">
            <x-public.status-badge status="unlisted" />
            @if ($shareLink->expires_at)
                <span class="text-sm muted-copy">{{ __('Expires :date', ['date' => $shareLink->expires_at->translatedFormat('F j, Y')]) }}</span>
            @endif
        </div>
        <article class="paper-surface p-6 sm:p-10 lg:p-14">
            <header class="border-b hairline pb-8">
                <p class="eyebrow">{{ __('Shared privately') }}</p>
                <h1 id="shared-entry-title" class="editorial-title mt-3 text-4xl leading-tight sm:text-5xl">{{ filled($entry->title) ? $entry->title : __('Untitled memory') }}</h1>
                @if ($entry->localOccurredAt())
                    <time class="muted-copy mt-4 block text-sm" datetime="{{ $entry->localOccurredAt()->toAtomString() }}">{{ $entry->localOccurredAt()->translatedFormat('F j, Y') }}</time>
                @endif
            </header>
            <div class="editorial-copy mt-8">{!! app(\App\Services\RichTextSanitizer::class)->sanitize($entry->body) !!}</div>

            @if ($shareLink->include_attachments && $sharedAttachments->isNotEmpty())
                <section class="mt-10 border-t hairline pt-7" aria-labelledby="shared-attachments">
                    <h2 id="shared-attachments" class="editorial-title text-2xl">{{ __('Shared attachments') }}</h2>
                    <ul class="mt-4 grid gap-2" role="list">
                        @foreach ($sharedAttachments as $attachment)
                            <li>
                                <a class="button-secondary" href="{{ route('shares.attachments.show', ['token' => $token, 'attachment' => $attachment]) }}">
                                    {{ $attachment->download_name ?: $attachment->original_name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </article>
        <p class="muted-copy mx-auto mt-5 max-w-2xl text-center text-xs leading-5">{{ __('This link is unlisted. Please respect the owner’s intended audience and do not redistribute it.') }}</p>
    </section>
@endsection
