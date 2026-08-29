@php($createdShareUrl = session('created_share_url'))

@if ($createdShareUrl)
    <div class="rounded-lg border border-success-300 bg-success-50 p-5 text-success-900 dark:border-success-700 dark:bg-success-950 dark:text-success-100" aria-live="polite">
        <div class="flex items-start gap-3">
            <x-filament::icon icon="heroicon-o-check-circle" class="mt-0.5 size-5 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ __('Private link created') }}</p>
                <p class="mt-1 text-sm leading-6">{{ __('Copy it now. For security, the full link is shown only once.') }}</p>
                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <input type="text" readonly value="{{ $createdShareUrl }}" class="fi-input min-w-0 flex-1 rounded-lg border border-success-300 bg-white px-3 py-2 text-sm text-gray-950 dark:border-success-700 dark:bg-gray-900 dark:text-white" aria-label="{{ __('Created private link') }}">
                    <button type="button" class="fi-btn rounded-lg bg-success-700 px-4 py-2 text-sm font-semibold text-white" data-copy-url="{{ $createdShareUrl }}">{{ __('Copy link') }}</button>
                </div>
            </div>
        </div>
    </div>
@endif
