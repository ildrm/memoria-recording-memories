<x-filament-panels::page>
    <section aria-labelledby="system-health-summary">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <h2 id="system-health-summary" class="font-[Iowan_Old_Style,Palatino_Linotype,Georgia,serif] text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ __('A privacy-safe operational snapshot') }}
                </h2>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    {{ __('These bounded checks report reachability and counts only. They never display hosts, credentials, queue payloads, exception text, or private diary content.') }}
                </p>
                @if ($checkedAt !== '')
                    <p class="mt-2 text-xs text-gray-700 dark:text-gray-300">
                        {{ __('Last checked :time', ['time' => \Carbon\CarbonImmutable::parse($checkedAt)->diffForHumans()]) }}
                    </p>
                @endif
            </div>
            <x-filament::button
                type="button"
                color="gray"
                outlined
                icon="heroicon-o-arrow-path"
                wire:click="refreshChecks"
                wire:loading.attr="disabled"
                wire:target="refreshChecks"
            >
                <span wire:loading.remove wire:target="refreshChecks">{{ __('Run checks again') }}</span>
                <span wire:loading wire:target="refreshChecks">{{ __('Checking…') }}</span>
            </x-filament::button>
        </div>

        <dl class="mt-7 grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-live="polite">
            @foreach ($checks as $key => $check)
                @php
                    $statusClasses = match ($check['status']) {
                        'success' => 'border-success-200 bg-success-50 text-success-800 dark:border-success-800 dark:bg-success-950 dark:text-success-200',
                        'danger' => 'border-danger-200 bg-danger-50 text-danger-800 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-200',
                        default => 'border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-200',
                    };
                    $statusIcon = match ($check['status']) {
                        'success' => 'heroicon-o-check-circle',
                        'danger' => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-information-circle',
                    };
                @endphp
                <div wire:key="system-health-{{ $key }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <dt class="text-sm font-semibold text-gray-600 dark:text-gray-300">{{ $check['label'] }}</dt>
                    <dd class="mt-1 flex items-start justify-between gap-4 text-lg font-semibold text-gray-950 dark:text-white">
                        <span>{{ $check['state'] }}</span>
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg border {{ $statusClasses }}" aria-hidden="true">
                            <x-filament::icon :icon="$statusIcon" class="size-5" />
                        </span>
                    </dd>
                    <dd class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $check['description'] }}</dd>

                    @if ($key === 'queue' && $counts['pending_jobs'] !== null)
                        <dd class="mt-4 border-t border-gray-200 pt-3 text-sm font-medium text-gray-800 dark:border-gray-700 dark:text-gray-100">
                            {{ trans_choice(':count stored job pending|:count stored jobs pending', $counts['pending_jobs'], ['count' => $counts['pending_jobs']]) }}
                        </dd>
                    @elseif ($key === 'failed_jobs' && $counts['failed_jobs'] !== null)
                        <dd class="mt-4 border-t border-gray-200 pt-3 text-sm font-medium text-gray-800 dark:border-gray-700 dark:text-gray-100">
                            {{ trans_choice(':count failed job record|:count failed job records', $counts['failed_jobs'], ['count' => $counts['failed_jobs']]) }}
                        </dd>
                    @endif
                </div>
            @endforeach
        </dl>

        <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-5 text-sm leading-6 text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
            <p class="font-semibold text-gray-950 dark:text-white">{{ __('What this page does not prove') }}</p>
            <p class="mt-1">{{ __('A configured queue connection does not prove a worker is alive. Use protected infrastructure monitoring and worker process supervision for liveness, latency, and alerting.') }}</p>
        </div>
    </section>
</x-filament-panels::page>
