@extends('layouts.public')

@section('title', __('Privacy notice template — :name', ['name' => config('app.name', 'Memoria')]))
@section('description', __('A pre-launch privacy notice template awaiting operator review.'))
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    <article class="mx-auto w-full max-w-[58rem] px-5 py-14 sm:px-8 sm:py-20 lg:px-10">
        <header class="max-w-[46rem]">
            <p class="eyebrow">{{ __('Pre-launch document') }}</p>
            <h1 class="editorial-title mt-4 text-[clamp(2.6rem,7vw,5rem)] leading-[1.02]">{{ __('Privacy notice template') }}</h1>
            <p class="muted-copy mt-6 text-lg leading-8">
                {{ __('This is a product template, not a final legal notice. The service operator must review it, identify the actual operator and vendors, add jurisdiction-specific terms, and obtain legal advice before launch.') }}
            </p>
        </header>

        <div class="paper-surface mt-10 border-s-4 border-s-[var(--accent)] p-6 sm:p-8" role="note">
            <h2 class="editorial-title text-2xl">{{ __('Operator review required') }}</h2>
            <p class="muted-copy mt-3 leading-7">
                {{ __('Do not rely on this placeholder as a description of a deployed service. Hosting, analytics, email, social providers, retention periods, lawful bases, contact details, and user rights must be verified against the real production configuration.') }}
            </p>
        </div>

        <div class="editorial-copy mt-12 max-w-[70ch]">
            <h2>{{ __('What the final notice should explain') }}</h2>
            <ul>
                <li>{{ __('Who operates the service and how to contact them about privacy.') }}</li>
                <li>{{ __('Which account, diary, attachment, publication, security, and technical data is processed, and why.') }}</li>
                <li>{{ __('Which processors and external publishing providers receive data, in which countries, and under which safeguards.') }}</li>
                <li>{{ __('Retention and deletion rules, including backups, exports, audit metadata, moderation records, and public derivatives.') }}</li>
                <li>{{ __('How people can exercise applicable access, correction, portability, objection, restriction, and deletion rights.') }}</li>
                <li>{{ __('How cookies, browser sessions, logs, rate limits, and security monitoring work in the deployed environment.') }}</li>
            </ul>

            <h2>{{ __('Privacy choices already visible in the product') }}</h2>
            <p>{{ __('The interface separates private memories from independently reviewed public copies, offers account export and deletion controls, and makes sharing and search-indexing choices explicit. The final notice must confirm how those controls behave in the production deployment.') }}</p>
        </div>
    </article>
@endsection
