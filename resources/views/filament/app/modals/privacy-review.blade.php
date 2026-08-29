@php
    $reviewMedia = $publication->relationLoaded('media') ? $publication->media : collect();
    $topics = is_array($publication->topics) ? $publication->topics : [];
    $reviewProof = str($fingerprint)->substr(0, 12)->upper();
@endphp

<div
    class="memoria-review-gate grid gap-6"
    tabindex="-1"
    x-init="$nextTick(() => $el.focus())"
    aria-describedby="privacy-review-boundary"
>
    <section class="memoria-review-proof" aria-labelledby="privacy-review-version">
        <div class="flex min-w-0 items-start gap-3">
            <x-filament::icon icon="heroicon-o-finger-print" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div class="min-w-0">
                <p id="privacy-review-version" class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ __('Exact public revision :revision', ['revision' => $publication->revision]) }}
                </p>
                <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">
                    {{ __('Review proof :proof · Last changed :time', [
                        'proof' => $reviewProof,
                        'time' => $publication->updated_at?->diffForHumans() ?? __('just now'),
                    ]) }}
                </p>
            </div>
        </div>
        <span class="memoria-review-step">{{ __('Gate 1 of 2') }}</span>
    </section>

    <p id="privacy-review-boundary" class="text-sm leading-6 text-gray-600 dark:text-gray-300">
        {{ trans_choice(
            'This proof covers the current title, story, public topics, audience settings, and :count sanitized public image. Any change invalidates it and requires a new review.|This proof covers the current title, story, public topics, audience settings, and :count sanitized public images. Any change invalidates it and requires a new review.',
            $reviewMedia->count(),
            ['count' => $reviewMedia->count()],
        ) }}
    </p>

    <section aria-labelledby="privacy-review-prompts">
        <div class="flex items-baseline justify-between gap-4">
            <h3 id="privacy-review-prompts" class="font-semibold text-gray-950 dark:text-white">{{ __('Review prompts') }}</h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count prompt|:count prompts', count($warnings), ['count' => count($warnings)]) }}</span>
        </div>

        @if (empty($warnings))
            <div class="mt-3 flex items-start gap-3 rounded-lg border border-success-300 bg-success-50 p-4 text-sm leading-6 text-success-900 dark:border-success-700 dark:bg-success-950 dark:text-success-100">
                <x-filament::icon icon="heroicon-o-check-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <div>
                    <p class="font-semibold">{{ __('No automated warnings were found.') }}</p>
                    <p class="mt-1">{{ __('Read the whole story yourself. Automated checks cannot understand every private detail or personal context.') }}</p>
                </div>
            </div>
        @else
            <ul class="memoria-review-warning-list mt-3" role="list">
                @foreach ($warnings as $warning)
                    <li>
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        <span>{{ __(is_array($warning) ? ($warning['message'] ?? __('Review this detail carefully.')) : $warning) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="privacy-review-media">
        <div class="flex items-baseline justify-between gap-4">
            <h3 id="privacy-review-media" class="font-semibold text-gray-950 dark:text-white">{{ __('Public images in this proof') }}</h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count image|:count images', $reviewMedia->count(), ['count' => $reviewMedia->count()]) }}</span>
        </div>

        @if ($reviewMedia->isEmpty())
            <div class="memoria-review-empty mt-3">
                <x-filament::icon icon="heroicon-o-photo" class="size-5 shrink-0" aria-hidden="true" />
                <p>{{ __('No public images are selected. The exact preview will show a text-only story.') }}</p>
            </div>
        @else
            <ul class="memoria-review-media-grid mt-3" role="list">
                @foreach ($reviewMedia->take(6) as $medium)
                    <li>
                        <x-public.publication-image
                            :media="$medium"
                            :alt="$medium->alt_text"
                            :preview="true"
                            usage="card"
                            class="aspect-[4/3] w-full object-cover"
                        />
                        <div class="border-t border-gray-200 px-3 py-2 text-xs leading-5 text-gray-600 dark:border-white/10 dark:text-gray-300">
                            <p class="line-clamp-2">{{ $medium->alt_text ?: __('Alternative text is missing') }}</p>
                            @if ($medium->is_featured)
                                <span class="mt-1 inline-flex font-semibold text-primary-700 dark:text-primary-300">{{ __('Featured image') }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($reviewMedia->count() > 6)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count additional image appears in the exact preview.|:count additional images appear in the exact preview.', $reviewMedia->count() - 6, ['count' => $reviewMedia->count() - 6]) }}</p>
            @endif
        @endif
    </section>

    @if ($topics !== [])
        <section aria-labelledby="privacy-review-topics">
            <h3 id="privacy-review-topics" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Public topics') }}</h3>
            <ul class="mt-2 flex flex-wrap gap-2" role="list">
                @foreach ($topics as $topic)
                    <li class="rounded-md border border-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:border-white/10 dark:text-gray-200">#{{ $topic }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="memoria-private-note">
        <x-filament::icon icon="heroicon-o-lock-closed" class="size-5" aria-hidden="true" />
        <p>{{ __('The story remains private after this confirmation. The next gate is a faithful public-page preview; publishing still requires a separate explicit action.') }}</p>
    </div>
</div>
