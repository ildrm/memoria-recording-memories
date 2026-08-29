@php
    $versionOccurredAt = $version->occurred_at?->setTimezone($version->timezone ?: 'UTC');
    $mood = $version->custom_mood
        ?: ($version->mood instanceof \App\Enums\Mood ? $version->mood->label() : null);
    $changedFields = collect([
        'title' => $version->title !== $entry->title ? __('Title') : null,
        'body' => $version->body !== $entry->body ? __('Writing') : null,
        'occurred_at' => $version->occurred_at?->toIso8601String() !== $entry->occurred_at?->toIso8601String() ? __('Date or time') : null,
        'mood' => ($version->mood?->value ?? null) !== ($entry->mood?->value ?? null) || $version->custom_mood !== $entry->custom_mood ? __('Mood') : null,
        'location' => $version->location_name !== $entry->location_name ? __('Place') : null,
    ])->filter();
@endphp

<div class="grid gap-6">
    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-950">
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Saved') }}</dt>
                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $version->created_at?->translatedFormat('F j, Y · H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Reason') }}</dt>
                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ str($version->reason ?: __('Automatic save'))->replace('_', ' ')->headline() }}</dd>
            </div>
            @if ($versionOccurredAt)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Memory time') }}</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $versionOccurredAt->translatedFormat('F j, Y · H:i') }} · {{ $version->timezone }}</dd>
                </div>
            @endif
            @if ($mood)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Mood') }}</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $mood }}</dd>
                </div>
            @endif
            @if ($version->location_name)
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Place') }}</dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $version->location_name }}</dd>
                </div>
            @endif
        </dl>
    </div>

    <section aria-labelledby="version-changes-title">
        <h3 id="version-changes-title" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Different from the current memory') }}</h3>
        @if ($changedFields->isEmpty())
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('This snapshot matches the fields shown in the current memory.') }}</p>
        @else
            <ul class="mt-3 flex flex-wrap gap-2" role="list">
                @foreach ($changedFields as $field)
                    <li class="rounded-md border border-warning-200 bg-warning-50 px-2.5 py-1 text-xs font-semibold text-warning-800 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-200">{{ $field }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-7" aria-labelledby="version-title">
        <h3 id="version-title" class="font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
            {{ filled($version->title) ? $version->title : __('Untitled memory') }}
        </h3>
        <div class="prose prose-stone mt-6 max-w-none dark:prose-invert">
            {!! app(\App\Services\RichTextSanitizer::class)->sanitize($version->body) !!}
        </div>
    </article>
</div>
