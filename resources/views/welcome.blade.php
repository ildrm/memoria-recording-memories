@extends('layouts.public')

@section('title', __('Memoria — Your private life, remembered'))
@section('description', __('A private-by-default diary for preserving memories and publishing only the stories you deliberately choose.'))

@section('content')
    <section class="mx-auto grid w-full max-w-7xl gap-12 px-5 pb-22 pt-14 sm:px-8 sm:pt-20 lg:grid-cols-[minmax(0,1.1fr)_minmax(21rem,0.72fr)] lg:items-center lg:gap-20 lg:px-10 lg:pb-30 lg:pt-28" aria-labelledby="hero-title">
        <div>
            <x-public.privacy-seal />
            <h1 id="hero-title" class="editorial-title mt-7 max-w-4xl text-[clamp(3rem,8vw,6.8rem)] leading-[0.96]">
                {{ __('Your life, kept') }}<br>
                <span class="text-[var(--accent)]">{{ __('on your terms.') }}</span>
            </h1>
            <p class="muted-copy mt-7 max-w-2xl text-lg leading-8 sm:text-xl">
                {{ __('Write the unedited truth in private. Organize a lifetime of memories. When a story feels ready, shape a separate public version and share only that.') }}
            </p>
            <div class="mt-9 flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                <a href="{{ url('/app/register') }}" class="button-primary w-full sm:w-auto">
                    {{ __('Start your private journal') }}
                    <span aria-hidden="true">&rarr;</span>
                </a>
                <span class="inline-flex items-center gap-2 text-sm muted-copy">
                    <svg class="size-4 text-[var(--accent)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg>
                    {{ __('No memory is public by default') }}
                </span>
            </div>
        </div>

        <div class="paper-surface relative p-5 sm:p-7" aria-label="{{ __('A private memory preview') }}">
            <div class="absolute -top-4 right-5 h-14 w-3 rounded-b-sm bg-memory-500 shadow-sm dark:bg-memory-300" aria-hidden="true"></div>
            <div class="flex items-center justify-between gap-3 border-b hairline pb-5">
                <div>
                    <p class="eyebrow">{{ __('Tuesday, 14 May') }}</p>
                    <p class="editorial-title mt-1 text-2xl">{{ __('The kitchen window') }}</p>
                </div>
                <x-public.status-badge />
            </div>
            <div class="editorial-copy py-7 text-[1.05rem]">
                <p>{{ __('Rain traced the glass while the coffee cooled beside me. I wrote down the small detail I knew I would otherwise forget…') }}</p>
            </div>
            <div class="flex items-center justify-between gap-3 border-t hairline pt-4 text-xs muted-copy">
                <span class="inline-flex items-center gap-2">
                    <span class="size-2 rounded-full bg-sage-500" aria-hidden="true"></span>
                    {{ __('Saved privately') }}
                </span>
                <span>{{ __('Travel journal') }}</span>
            </div>
        </div>
    </section>

    <section class="border-y hairline bg-[var(--surface)]" aria-labelledby="promise-title">
        <div class="mx-auto grid w-full max-w-7xl gap-10 px-5 py-18 sm:px-8 lg:grid-cols-[0.7fr_1.3fr] lg:gap-20 lg:px-10 lg:py-24">
            <div>
                <p class="eyebrow">{{ __('One deliberate boundary') }}</p>
                <h2 id="promise-title" class="editorial-title mt-4 text-4xl leading-tight sm:text-5xl">{{ __('Private memory and public story are never the same record.') }}</h2>
                <p class="muted-copy mt-5 leading-7">{{ __('Publishing creates a separate version you can edit, review, preview, and unpublish without changing your private original.') }}</p>
            </div>

            <ol class="memory-ribbon grid gap-10 ps-8 sm:ps-10">
                <li class="relative grid gap-2 sm:grid-cols-[8rem_1fr] sm:gap-6">
                    <span class="memory-ribbon-dot absolute -start-[2rem] top-1 sm:-start-[2.5rem]" aria-hidden="true"></span>
                    <span class="eyebrow">{{ __('01 · Write') }}</span>
                    <div>
                        <h3 class="editorial-title text-2xl">{{ __('Keep the whole memory private') }}</h3>
                        <p class="muted-copy mt-2 leading-7">{{ __('Words, photos, people, places, and attachments begin as Only me. Saving is never publishing.') }}</p>
                    </div>
                </li>
                <li class="relative grid gap-2 sm:grid-cols-[8rem_1fr] sm:gap-6">
                    <span class="memory-ribbon-dot absolute -start-[2rem] top-1 sm:-start-[2.5rem]" aria-hidden="true"></span>
                    <span class="eyebrow">{{ __('02 · Shape') }}</span>
                    <div>
                        <h3 class="editorial-title text-2xl">{{ __('Create a controlled public version') }}</h3>
                        <p class="muted-copy mt-2 leading-7">{{ __('Remove private details, select approved media, and complete a clear privacy review.') }}</p>
                    </div>
                </li>
                <li class="relative grid gap-2 sm:grid-cols-[8rem_1fr] sm:gap-6">
                    <span class="memory-ribbon-dot absolute -start-[2rem] top-1 sm:-start-[2.5rem]" aria-hidden="true"></span>
                    <span class="eyebrow">{{ __('03 · Choose') }}</span>
                    <div>
                        <h3 class="editorial-title text-2xl">{{ __('Publish only after an explicit final action') }}</h3>
                        <p class="muted-copy mt-2 leading-7">{{ __('Preview the exact public page, then publish now or schedule it. Your source memory stays private.') }}</p>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    <section class="mx-auto w-full max-w-7xl px-5 py-20 sm:px-8 lg:px-10 lg:py-28" aria-labelledby="features-title">
        <div class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-20">
            <div class="lg:sticky lg:top-10 lg:self-start">
                <p class="eyebrow">{{ __('A home for a lifetime') }}</p>
                <h2 id="features-title" class="editorial-title mt-4 text-4xl sm:text-5xl">{{ __('Memory deserves more than a stream.') }}</h2>
                <p class="muted-copy mt-5 max-w-lg leading-7">{{ __('Memoria gives the years shape without turning your private life into a productivity dashboard.') }}</p>
            </div>

            <div class="divide-y hairline border-y hairline">
                @foreach ([
                    [__('Journals with room to grow'), __('Gather travels, family chapters, daily reflections, and long-running themes without losing chronology.'), '01'],
                    [__('Find the detail you remember'), __('Move through timeline and calendar views, then search your own entries with private, owner-scoped results.'), '02'],
                    [__('Private media stays private'), __('Original attachments use protected storage. Only explicitly approved publication media can appear publicly.'), '03'],
                    [__('Share narrowly when public is too broad'), __('Create revocable, expiring, password-protected links that never appear in public navigation.'), '04'],
                ] as [$title, $description, $number])
                    <article class="grid gap-4 py-8 sm:grid-cols-[3rem_1fr] sm:gap-6">
                        <span class="font-mono text-xs text-memory-500 dark:text-memory-300">{{ $number }}</span>
                        <div>
                            <h3 class="editorial-title text-2xl">{{ $title }}</h3>
                            <p class="muted-copy mt-2 leading-7">{{ $description }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section id="privacy" class="bg-sage-900 text-paper-50 dark:bg-night-900" aria-labelledby="privacy-title">
        <div class="mx-auto grid w-full max-w-7xl gap-12 px-5 py-20 sm:px-8 lg:grid-cols-[1fr_1fr] lg:gap-24 lg:px-10 lg:py-28">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.15em] text-sage-300">{{ __('Privacy without theatre') }}</p>
                <h2 id="privacy-title" class="mt-4 font-[var(--font-editorial)] text-4xl font-semibold leading-tight tracking-[-0.03em] sm:text-5xl">{{ __('Clear boundaries you can understand.') }}</h2>
                <p class="mt-6 max-w-xl text-base leading-8 text-paper-200">{{ __('We describe the safeguards the product actually provides, without vague “military-grade” promises or claims stronger than the architecture.') }}</p>
            </div>
            <ul class="grid gap-6" role="list">
                @foreach ([
                    __('Every private query is scoped to its owner and protected by authorization policies.'),
                    __('Private files are not permanent public URLs; access is checked before delivery.'),
                    __('Publications use independent snapshots, so private edits do not silently republish.'),
                    __('Exports and share links expire, can be revoked, and remain inaccessible to other accounts.'),
                ] as $principle)
                    <li class="flex gap-4 border-b border-white/15 pb-6 last:border-0">
                        <svg class="mt-1 size-5 shrink-0 text-sage-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg>
                        <span class="leading-7 text-paper-100">{{ $principle }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="mx-auto w-full max-w-5xl px-5 py-22 text-center sm:px-8 lg:py-30" aria-labelledby="final-cta-title">
        <x-public.privacy-seal />
        <h2 id="final-cta-title" class="editorial-title mx-auto mt-6 max-w-3xl text-4xl leading-tight sm:text-6xl">{{ __('Begin with the memory you do not want to lose.') }}</h2>
        <p class="muted-copy mx-auto mt-5 max-w-2xl text-lg leading-8">{{ __('Your diary begins private. If you never publish a word, it remains a complete product—not an unfinished social profile.') }}</p>
        <a href="{{ url('/app/register') }}" class="button-primary mt-8">{{ __('Start your private journal') }}</a>
    </section>
@endsection
