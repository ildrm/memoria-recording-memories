@props([
    'action',
    'reasons',
    'label' => __('Report'),
])

<details class="inline-block max-w-full align-top">
    <summary class="cursor-pointer font-semibold text-[var(--muted)] hover:text-[var(--ink)]">{{ $label }}</summary>
    <form method="POST" action="{{ $action }}" class="paper-surface mt-3 grid min-w-[min(30rem,calc(100vw-4rem))] gap-3 p-4 text-start">
        @csrf
        <div>
            <label for="report-reason-{{ md5($action) }}" class="text-sm font-semibold">{{ __('Reason') }}</label>
            <select id="report-reason-{{ md5($action) }}" name="reason" required class="form-field mt-2">
                <option value="">{{ __('Choose a reason') }}</option>
                @foreach ($reasons as $value => $reasonLabel)
                    <option value="{{ $value }}">{{ $reasonLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="report-details-{{ md5($action) }}" class="text-sm font-semibold">{{ __('Details (optional)') }}</label>
            <textarea id="report-details-{{ md5($action) }}" name="details" rows="3" maxlength="2000" class="form-field mt-2 resize-y" placeholder="{{ __('Share only what moderators need to assess the concern.') }}"></textarea>
        </div>
        <p class="muted-copy text-xs leading-5">{{ __('Reports are private to the moderation team. The author will not see your report details.') }}</p>
        <button type="submit" class="button-secondary justify-self-start">{{ __('Submit report') }}</button>
    </form>
</details>
