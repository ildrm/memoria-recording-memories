@props(['status' => 'private', 'label' => null])

@php
    $details = match ($status) {
        'public', 'published' => [__('Published publicly'), 'globe'],
        'unlisted' => [__('Unlisted link'), 'link'],
        'shared' => [__('Shared privately'), 'users'],
        'scheduled' => [__('Scheduled'), 'clock'],
        'draft' => [__('Publication draft'), 'document'],
        default => [__('Only me'), 'lock'],
    };
@endphp

<span {{ $attributes->class('privacy-seal') }}>
    @switch($details[1])
        @case('globe')
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.5 2.5 3.6 5.5 3.6 9S14.5 18.5 12 21c-2.5-2.5-3.6-5.5-3.6-9S9.5 5.5 12 3Z" /></svg>
            @break
        @case('link')
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="m9 15 6-6m-8.5 9.5-1 1a3.5 3.5 0 0 1-5-5l4-4a3.5 3.5 0 0 1 5 0m8 5 1-1a3.5 3.5 0 0 0-5-5l-1 1" /></svg>
            @break
        @case('users')
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="8" r="3" /><path stroke-linecap="round" d="M3.5 19a5.5 5.5 0 0 1 11 0M16 5.5a3 3 0 0 1 0 5.8M17 14a5 5 0 0 1 3.5 5" /></svg>
            @break
        @case('clock')
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 2" /></svg>
            @break
        @case('document')
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v5h4" /></svg>
            @break
        @default
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2" /><path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>
    @endswitch
    {{ $label ?? $details[0] }}
</span>

