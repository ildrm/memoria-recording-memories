@extends('layouts.public')

@section('title', __('Terms template — :name', ['name' => config('app.name', 'Memoria')]))
@section('description', __('A pre-launch terms template awaiting operator review.'))
@section('robots', 'noindex,nofollow,noarchive')

@section('content')
    <article class="mx-auto w-full max-w-[58rem] px-5 py-14 sm:px-8 sm:py-20 lg:px-10">
        <header class="max-w-[46rem]">
            <p class="eyebrow">{{ __('Pre-launch document') }}</p>
            <h1 class="editorial-title mt-4 text-[clamp(2.6rem,7vw,5rem)] leading-[1.02]">{{ __('Terms of service template') }}</h1>
            <p class="muted-copy mt-6 text-lg leading-8">
                {{ __('This page is a drafting checklist, not binding terms. The service operator must replace it with reviewed terms that match the real product, business model, jurisdiction, age requirements, and support arrangements before launch.') }}
            </p>
        </header>

        <div class="paper-surface mt-10 border-s-4 border-s-[var(--accent)] p-6 sm:p-8" role="note">
            <h2 class="editorial-title text-2xl">{{ __('Operator review required') }}</h2>
            <p class="muted-copy mt-3 leading-7">
                {{ __('No warranty, liability limit, governing law, dispute process, payment term, or service commitment is asserted by this placeholder. Those decisions require the operator’s explicit approval and appropriate legal review.') }}
            </p>
        </div>

        <div class="editorial-copy mt-12 max-w-[70ch]">
            <h2>{{ __('What the final terms should cover') }}</h2>
            <ul>
                <li>{{ __('Eligibility, account security, accurate registration details, and responsibility for recovery codes and connected providers.') }}</li>
                <li>{{ __('Acceptable use for private storage, public publications, comments, reactions, reports, and social publishing.') }}</li>
                <li>{{ __('Ownership and permissions for uploaded writing and media, plus the limited license needed to operate selected features.') }}</li>
                <li>{{ __('Moderation, reports, public-content removal, account suspension, appeals, and legally required preservation or disclosure.') }}</li>
                <li>{{ __('Exports, cancellation, deletion timing, service availability, changes to features, and termination consequences.') }}</li>
                <li>{{ __('Fees, refunds, warranties, liability, indemnity, governing law, and dispute resolution if applicable.') }}</li>
            </ul>

            <h2>{{ __('Publishing remains a deliberate act') }}</h2>
            <p>{{ __('Private diary entries and their attachments are not public by default. A person creates and reviews a separate publication copy before choosing a website or external social destination. Final terms should describe those boundaries without promising behavior the deployed system cannot guarantee.') }}</p>
        </div>
    </article>
@endsection
