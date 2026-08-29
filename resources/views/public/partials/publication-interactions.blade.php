@php
    $verifiedReader = auth()->check() && auth()->user()->hasVerifiedEmail();
    $comments = $comments ?? collect();
    $reactionOptions = [
        'like' => [__('Like'), (int) ($publication->like_reactions_count ?? 0)],
        'love' => [__('Love'), (int) ($publication->love_reactions_count ?? 0)],
        'support' => [__('Support'), (int) ($publication->support_reactions_count ?? 0)],
        'insightful' => [__('Insightful'), (int) ($publication->insightful_reactions_count ?? 0)],
    ];
    $reportReasons = [
        'spam' => __('Spam'),
        'harassment' => __('Harassment'),
        'hate' => __('Hate'),
        'safety' => __('Safety concern'),
        'copyright' => __('Copyright'),
        'privacy' => __('Privacy concern'),
        'other' => __('Other'),
    ];
@endphp

<div class="mx-auto mt-16 grid max-w-[48rem] gap-12 border-t hairline pt-10">
    @if ($publication->reactions_enabled)
        <section aria-labelledby="reactions-title">
            <p class="eyebrow">{{ __('Reader reactions') }}</p>
            <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 id="reactions-title" class="editorial-title text-3xl">{{ __('Leave a quiet mark') }}</h2>
                    <p class="muted-copy mt-2 text-sm">{{ trans_choice(':count reaction|:count reactions', (int) ($publication->reactions_count ?? 0), ['count' => (int) ($publication->reactions_count ?? 0)]) }}</p>
                </div>
            </div>

            @if ($verifiedReader)
                <div class="mt-5 flex flex-wrap gap-2" aria-label="{{ __('Choose a reaction') }}">
                    @foreach ($reactionOptions as $reactionValue => [$reactionLabel, $reactionCount])
                        <form method="POST" action="{{ route('publications.reactions.store', $publication) }}">
                            @csrf
                            <input type="hidden" name="type" value="{{ $reactionValue }}">
                            <button type="submit" class="button-secondary">
                                <span>{{ $reactionLabel }}</span>
                                <span class="rounded-full bg-[var(--page-bg)] px-2 py-0.5 text-xs tabular-nums" aria-label="{{ trans_choice(':count :reaction reaction|:count :reaction reactions', $reactionCount, ['count' => $reactionCount, 'reaction' => $reactionLabel]) }}">{{ $reactionCount }}</span>
                            </button>
                        </form>
                    @endforeach
                </div>
                @error('type')
                    <p class="mt-3 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>
                @enderror
            @else
                <p class="muted-copy mt-4 text-sm">
                    <a href="{{ url('/app/login') }}" class="font-semibold text-[var(--accent-strong)] underline underline-offset-4">{{ __('Sign in') }}</a>
                    {{ __('with a verified account to react.') }}
                </p>
            @endif
        </section>
    @endif

    @if ($publication->comments_enabled)
        <section aria-labelledby="comments-title">
            <p class="eyebrow">{{ __('Conversation') }}</p>
            <h2 id="comments-title" class="editorial-title mt-2 text-3xl">
                {{ __('Responses') }}
                <span class="align-middle font-sans text-sm font-medium tracking-normal muted-copy">{{ (int) ($publication->approved_comments_count ?? $comments->count()) }}</span>
            </h2>

            @if ($verifiedReader)
                <form method="POST" action="{{ route('publications.comments.store', $publication) }}" class="paper-surface mt-6 grid gap-4 p-5 sm:p-6">
                    @csrf
                    <div>
                        <label for="new-comment" class="text-sm font-semibold">{{ __('Add a response') }}</label>
                        <p id="new-comment-help" class="muted-copy mt-1 text-sm">{{ __('Responses are reviewed before they appear publicly. Please be thoughtful and avoid sharing private information.') }}</p>
                        <textarea id="new-comment" name="body" rows="4" maxlength="2000" required class="form-field mt-3 resize-y" aria-describedby="new-comment-help @error('body') new-comment-error @enderror">{{ old('body') }}</textarea>
                        @error('body')
                            <p id="new-comment-error" class="mt-2 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="muted-copy text-xs">{{ __('Maximum 2,000 characters') }}</span>
                        <button type="submit" class="button-primary">{{ __('Submit for review') }}</button>
                    </div>
                </form>
            @else
                <div class="paper-surface mt-6 p-5 sm:flex sm:items-center sm:justify-between sm:gap-5">
                    <p class="muted-copy text-sm leading-6">{{ __('A verified account is required to respond. This helps keep public conversations accountable.') }}</p>
                    <a href="{{ url('/app/login') }}" class="button-secondary mt-4 shrink-0 sm:mt-0">{{ __('Sign in to respond') }}</a>
                </div>
            @endif

            @if ($comments->isEmpty())
                <p class="muted-copy mt-7">{{ __('No public responses yet.') }}</p>
            @else
                <ol class="mt-7 grid gap-5">
                    @foreach ($comments as $comment)
                        <li class="paper-surface p-5 sm:p-6" id="comment-{{ $comment->getKey() }}">
                            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                                <span class="font-semibold">{{ $comment->author?->name ?? __('Reader') }}</span>
                                <time class="muted-copy" datetime="{{ $comment->created_at?->toAtomString() }}">{{ $comment->created_at?->diffForHumans() }}</time>
                            </div>
                            <p class="mt-3 whitespace-pre-line leading-7">{{ $comment->body }}</p>

                            @if ($verifiedReader)
                                <div class="mt-4 flex flex-wrap gap-4 text-sm">
                                    <details>
                                        <summary class="cursor-pointer font-semibold text-[var(--accent-strong)]">{{ __('Reply') }}</summary>
                                        <form method="POST" action="{{ route('publications.comments.store', $publication) }}" class="mt-3 grid min-w-[min(32rem,calc(100vw-5rem))] gap-3">
                                            @csrf
                                            <input type="hidden" name="parent_id" value="{{ $comment->getKey() }}">
                                            <label for="reply-{{ $comment->getKey() }}" class="sr-only">{{ __('Reply to :name', ['name' => $comment->author?->name ?? __('Reader')]) }}</label>
                                            <textarea id="reply-{{ $comment->getKey() }}" name="body" rows="3" maxlength="2000" required class="form-field resize-y" placeholder="{{ __('Write a thoughtful reply…') }}"></textarea>
                                            <button type="submit" class="button-secondary justify-self-start">{{ __('Submit reply for review') }}</button>
                                        </form>
                                    </details>
                                    <x-public.report-disclosure :action="route('comments.reports.store', $comment)" :reasons="$reportReasons" :label="__('Report response')" />
                                </div>
                            @endif

                            @if ($comment->relationLoaded('replies') && $comment->replies->isNotEmpty())
                                <ol class="mt-5 grid gap-4 border-s-2 border-[var(--border)] ps-4 sm:ps-6" aria-label="{{ __('Replies') }}">
                                    @foreach ($comment->replies as $reply)
                                        <li id="comment-{{ $reply->getKey() }}">
                                            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                                                <span class="font-semibold">{{ $reply->author?->name ?? __('Reader') }}</span>
                                                <time class="muted-copy" datetime="{{ $reply->created_at?->toAtomString() }}">{{ $reply->created_at?->diffForHumans() }}</time>
                                            </div>
                                            <p class="mt-2 whitespace-pre-line leading-7">{{ $reply->body }}</p>
                                            @if ($verifiedReader)
                                                <div class="mt-2 text-sm">
                                                    <x-public.report-disclosure :action="route('comments.reports.store', $reply)" :reasons="$reportReasons" :label="__('Report response')" />
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                            @if ((int) ($comment->approved_replies_count ?? 0) > $comment->replies->count())
                                <a
                                    href="{{ route('publications.comments.replies.index', ['username' => $profile->username, 'publicationSlug' => $publication->slug, 'comment' => $comment]) }}"
                                    class="button-quiet mt-5"
                                >
                                    {{ trans_choice('View all :count reply|View all :count replies', (int) $comment->approved_replies_count, ['count' => (int) $comment->approved_replies_count]) }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ol>
                @if ($comments instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $comments->hasPages())
                    <nav class="mt-7" aria-label="{{ __('Response pages') }}">
                        {{ $comments->withQueryString()->fragment('comments-title')->onEachSide(1)->links() }}
                    </nav>
                @endif
            @endif
        </section>
    @endif

    <section class="border-t hairline pt-7" aria-labelledby="story-safety-title">
        <h2 id="story-safety-title" class="text-sm font-semibold">{{ __('Story safety') }}</h2>
        @if ($verifiedReader)
            <div class="mt-2 text-sm">
                <x-public.report-disclosure :action="route('publications.reports.store', $publication)" :reasons="$reportReasons" :label="__('Report this story')" />
            </div>
        @else
            <p class="muted-copy mt-2 text-sm leading-6">
                {{ __('If this story raises a safety, privacy, or rights concern,') }}
                <a href="{{ url('/app/login') }}" class="font-semibold text-[var(--accent-strong)] underline underline-offset-4">{{ __('sign in to report it') }}</a>.
            </p>
        @endif
    </section>
</div>
