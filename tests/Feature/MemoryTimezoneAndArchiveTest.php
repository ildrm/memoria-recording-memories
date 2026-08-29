<?php

use App\Filament\App\Pages\Archive;
use App\Filament\App\Pages\Calendar;
use App\Filament\App\Pages\OnThisDay;
use App\Filament\App\Pages\Search;
use App\Filament\App\Pages\Settings;
use App\Filament\App\Pages\Timeline;
use App\Filament\App\Resources\EntryResource;
use App\Filament\App\Resources\JournalResource;
use App\Http\Middleware\ApplyUserPreferences;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('memory dates are derived in the timezone where the memory happened', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'occurred_at' => CarbonImmutable::parse('2026-01-01 21:15:00', 'UTC'),
        'timezone' => 'Asia/Tehran',
    ]);

    expect($entry->occurred_on?->toDateString())->toBe('2026-01-02')
        ->and($entry->localOccurredAt()?->format('Y-m-d H:i'))->toBe('2026-01-02 00:45');

    $entry->update(['timezone' => 'UTC']);

    expect($entry->refresh()->occurred_on?->toDateString())->toBe('2026-01-01');
});

test('the private panel applies the account timezone and only offers installed translations', function (): void {
    $owner = User::factory()->create();
    $owner->preferences()->update([
        'locale' => 'en',
        'timezone' => 'Asia/Tehran',
    ]);

    $this->actingAs($owner)
        ->get(Settings::getUrl())
        ->assertOk()
        ->assertSee('English')
        ->assertDontSee('Persian');

    $request = Request::create('/app');
    $request->setUserResolver(fn (): User => $owner);
    $applied = [];

    app(ApplyUserPreferences::class)->handle($request, function () use (&$applied) {
        $applied = [app()->getLocale(), FilamentTimezone::get()];

        return response('ok');
    });

    expect($applied)->toBe(['en', 'Asia/Tehran']);
});

test('calendar search and on this day use the intended local memory date', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-02 00:15:00', 'Asia/Tehran'));
    $owner = User::factory()->create();
    $owner->preferences()->update([
        'timezone' => 'Asia/Tehran',
        'on_this_day_enabled' => true,
    ]);
    $localJanuarySecond = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Midnight Tehran memory',
        'occurred_at' => CarbonImmutable::parse('2025-01-01 21:00:00', 'UTC'),
        'timezone' => 'Asia/Tehran',
    ]);
    Entry::factory()->for($owner, 'owner')->create([
        'title' => 'January first UTC memory',
        'occurred_at' => CarbonImmutable::parse('2025-01-01 12:00:00', 'UTC'),
        'timezone' => 'UTC',
    ]);
    Entry::factory()->count(2)->for($owner, 'owner')->sequence(
        ['title' => 'First final-day memory'],
        ['title' => 'Second final-day memory'],
    )->create([
        'occurred_at' => CarbonImmutable::parse('2025-01-31 12:00:00', 'UTC'),
        'timezone' => 'UTC',
    ]);

    expect($localJanuarySecond->occurred_on?->toDateString())->toBe('2025-01-02');

    $this->actingAs($owner)
        ->get(Calendar::getUrl(['month' => '2025-01']))
        ->assertOk()
        ->assertSee('Midnight Tehran memory')
        ->assertSee('First final-day memory')
        ->assertSee('Second final-day memory')
        ->assertSee('aria-label="2 memories"', false);

    $this->get(Search::getUrl([
        'date_from' => '2025-01-02',
        'date_to' => '2025-01-02',
    ]))
        ->assertOk()
        ->assertSee('Midnight Tehran memory')
        ->assertDontSee('January first UTC memory');

    $this->get(OnThisDay::getUrl())
        ->assertOk()
        ->assertSee('Midnight Tehran memory')
        ->assertDontSee('January first UTC memory');
});

test('archived memories and journals stay out of everyday views', function (): void {
    $owner = User::factory()->create();
    $activeEntry = Entry::factory()->for($owner, 'owner')->create(['title' => 'Visible daily memory']);
    $archivedEntry = Entry::factory()->for($owner, 'owner')->archived()->create(['title' => 'Quiet archived memory']);
    $activeJournal = Journal::factory()->for($owner, 'owner')->create(['name' => 'Visible journal']);
    $archivedJournal = Journal::factory()->for($owner, 'owner')->create([
        'name' => 'Archived journal',
        'archived_at' => now(),
    ]);

    expect($activeEntry->archived_at)->toBeNull()
        ->and($archivedEntry->archived_at)->not->toBeNull()
        ->and($activeJournal->archived_at)->toBeNull()
        ->and($archivedJournal->archived_at)->not->toBeNull();

    foreach ([Timeline::getUrl(), EntryResource::getUrl(), JournalResource::getUrl(), '/app'] as $url) {
        $response = $this->actingAs($owner)->get($url)->assertOk();
        $response->assertDontSee('Quiet archived memory');
    }

    $this->get(Timeline::getUrl())
        ->assertSee('Visible daily memory')
        ->assertDontSee('Quiet archived memory');

    $this->get(EntryResource::getUrl())
        ->assertSee('Visible daily memory')
        ->assertDontSee('Quiet archived memory');

    $this->get(JournalResource::getUrl())
        ->assertSee('Visible journal')
        ->assertDontSee('Archived journal');

    $this->get(Archive::getUrl())
        ->assertSee('Quiet archived memory')
        ->assertDontSee('Visible daily memory');
});
