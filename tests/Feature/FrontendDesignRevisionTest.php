<?php

use App\Filament\App\Resources\EntryResource;
use App\Filament\App\Resources\PublicationResource;
use App\Filament\App\Resources\PublicationResource\Pages\EditPublication;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\PublicationTarget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('the created share link renders a panel native clipboard handler', function (): void {
    session()->flash('created_share_url', 'https://memoria.example.test/shares/one-time-secret');

    $html = view('filament.app.components.created-share-link')->render();

    expect($html)
        ->toContain('window.navigator.clipboard.writeText')
        ->toContain('$tooltip');
});

test('the primary private indexes render while lazy loading is forbidden', function (): void {
    $owner = User::factory()->create();
    $journal = Journal::factory()->for($owner, 'owner')->create(['name' => 'Field notes']);
    $entry = Entry::factory()->forJournal($journal)->create(['title' => 'A quiet morning']);
    $publication = Publication::factory()->fromEntry($entry)->create(['title' => 'A public-safe morning']);
    $wasPreventingLazyLoading = Model::preventsLazyLoading();

    Model::preventLazyLoading();

    try {
        $this->actingAs($owner)
            ->get(EntryResource::getUrl())
            ->assertOk()
            ->assertSee('A quiet morning')
            ->assertSee('Field notes');

        $this->get(PublicationResource::getUrl())
            ->assertOk()
            ->assertSee($publication->title)
            ->assertSee('Separate version of a private memory');
    } finally {
        Model::preventLazyLoading($wasPreventingLazyLoading);
    }
});

test('the privacy review route opens the complete exact-version gate', function (): void {
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create([
        'title' => 'A deliberately public story',
        'body' => '<p>A public-safe draft that still needs human review.</p>',
        'revision' => 4,
    ]);
    $this->actingAs($owner);

    $reviewUrl = route('app.publications.privacy-review', $publication);
    $editUrl = PublicationResource::getUrl('edit', [
        'record' => $publication,
        'action' => 'privacyReview',
    ]);

    $this->get($reviewUrl)->assertRedirect($editUrl);

    Livewire::test(EditPublication::class, ['record' => $publication->getKey()])
        ->mountAction('privacyReview')
        ->assertMountedActionModalSee('Privacy gate · Step 1 of 2')
        ->assertMountedActionModalSee('Exact public revision 4')
        ->assertMountedActionModalSee('No public images are selected')
        ->assertMountedActionModalSee('Confirm review and open exact preview')
        ->callMountedAction()
        ->assertRedirect(route('app.publications.preview', $publication));
});

test('placeholder pixels never expand into public feature frames', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update([
        'username' => 'careful-writer',
        'display_name' => 'Careful Writer',
        'is_public' => true,
    ]);
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'text-only-story',
    ]);
    PublicationTarget::factory()->publishedWebsite($publication)->create();
    $placeholder = PublicationMedia::factory()->for($publication)->for($owner, 'owner')->create([
        'mime_type' => 'image/png',
        'is_featured' => true,
        'metadata' => ['width' => 1, 'height' => 1],
    ]);
    $placeholderUrl = route('publications.media.show', $placeholder);

    $this->get(route('profiles.show', 'careful-writer'))
        ->assertOk()
        ->assertDontSee($placeholderUrl, false);

    $this->get(route('publications.show', [
        'username' => 'careful-writer',
        'publicationSlug' => 'text-only-story',
    ]))
        ->assertOk()
        ->assertDontSee($placeholderUrl, false);
});
