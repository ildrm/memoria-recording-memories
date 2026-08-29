<x-filament-widgets::widget>
    <div class="memoria-dashboard-grid">
        <section class="memoria-welcome-card" aria-labelledby="dashboard-welcome">
            <div>
                <p class="memoria-eyebrow">{{ __('Your private place') }}</p>
                <h2 id="dashboard-welcome">{{ __('Welcome back, :name.', ['name' => str($user->name)->before(' ')]) }}</h2>
                <p>{{ __('Capture what matters. Every new memory stays visible only to you unless you deliberately create and publish a separate public version.') }}</p>
            </div>
            <div class="memoria-dashboard-actions">
                <x-filament::button :href="$writeUrl" tag="a" icon="heroicon-o-pencil-square">
                    {{ __('Write a memory') }}
                </x-filament::button>
                <x-filament::button :href="$timelineUrl" tag="a" color="gray" outlined icon="heroicon-o-queue-list">
                    {{ __('Open timeline') }}
                </x-filament::button>
            </div>
            <div class="memoria-private-note">
                <x-filament::icon icon="heroicon-o-lock-closed" class="h-5 w-5" />
                <span><strong>{{ __('Only you can see this.') }}</strong> {{ __('Publishing always requires a separate privacy review and confirmation.') }}</span>
            </div>
        </section>

        <aside class="memoria-glance-card" aria-labelledby="dashboard-glance">
            <p class="memoria-eyebrow">{{ __('At a glance') }}</p>
            <h2 id="dashboard-glance" class="sr-only">{{ __('Memory overview') }}</h2>
            <dl>
                <div>
                    <dt>{{ __('Favorites') }}</dt>
                    <dd>{{ $favoriteCount }}</dd>
                </div>
                <div>
                    <dt>{{ __('On this day') }}</dt>
                    <dd>{{ $onThisDayCount }}</dd>
                </div>
                <div>
                    <dt>{{ __('Next reminder') }}</dt>
                    <dd class="text-sm">{{ $nextReminder?->next_run_at?->setTimezone(\Filament\Support\Facades\FilamentTimezone::get())->translatedFormat('M j, H:i') ?? __('None scheduled') }}</dd>
                </div>
            </dl>
            <a href="{{ $calendarUrl }}">{{ __('View calendar') }} <span aria-hidden="true">&rarr;</span></a>
        </aside>

        <section class="memoria-recent-card" aria-labelledby="recent-memories">
            <div class="memoria-section-heading">
                <div>
                    <p class="memoria-eyebrow">{{ __('Memory ribbon') }}</p>
                    <h2 id="recent-memories">{{ __('Recently remembered') }}</h2>
                </div>
                <a href="{{ $timelineUrl }}">{{ __('See all') }}</a>
            </div>

            @if ($recentEntries->isEmpty())
                <div class="memoria-widget-empty">
                    <x-filament::icon icon="heroicon-o-book-open" class="h-7 w-7" />
                    <div>
                        <p>{{ __('Your first page is waiting.') }}</p>
                        <span>{{ __('A sentence is enough to begin.') }}</span>
                    </div>
                </div>
            @else
                <ol class="memoria-dashboard-timeline">
                    @foreach ($recentEntries as $entry)
                        @php($localOccurredAt = $entry->localOccurredAt())
                        <li>
                            <time datetime="{{ $localOccurredAt?->toAtomString() }}">
                                {{ $localOccurredAt?->translatedFormat('M j, Y') ?? __('Undated') }}
                            </time>
                            <a href="{{ \App\Filament\App\Resources\EntryResource::getUrl('edit', ['record' => $entry]) }}">
                                {{ filled($entry->title) ? $entry->title : __('Untitled memory') }}
                            </a>
                            <span>{{ $entry->journal?->name ?? __('Journal') }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
</x-filament-widgets::widget>
