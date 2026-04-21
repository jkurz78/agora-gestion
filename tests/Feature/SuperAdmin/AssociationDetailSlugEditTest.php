<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Exceptions\SlugImmutableException;
use App\Livewire\SuperAdmin\AssociationDetail;
use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->asso = Association::factory()->create(['slug' => 'ancien-slug']);
    $this->superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $this->actingAs($this->superAdmin);
});

// --- openSlugEditor / cancelSlugEdit toggles ---

it('openSlugEditor sets editingSlug to true and initialises newSlug', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->assertSet('editingSlug', true)
        ->assertSet('newSlug', 'ancien-slug');
});

it('cancelSlugEdit resets editingSlug and clears newSlug', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'something-else')
        ->call('cancelSlugEdit')
        ->assertSet('editingSlug', false)
        ->assertSet('newSlug', '');
});

// --- Happy path ---

it('super-admin can change the slug successfully', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'nouveau-slug')
        ->call('saveSlug')
        ->assertSet('editingSlug', false)
        ->assertSet('newSlug', '')
        ->assertHasNoErrors();

    expect($this->asso->fresh()->slug)->toBe('nouveau-slug');

    $log = SuperAdminAccessLog::where('action', 'update_slug')
        ->where('association_id', $this->asso->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->payload['old_slug'])->toBe('ancien-slug');
    expect($log->payload['new_slug'])->toBe('nouveau-slug');
});

// --- Validation : format ---

it('rejects slug with uppercase letters', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'Invalid-Slug')
        ->call('saveSlug')
        ->assertHasErrors(['newSlug']);

    expect($this->asso->fresh()->slug)->toBe('ancien-slug');
});

it('rejects slug with spaces', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'slug avec espaces')
        ->call('saveSlug')
        ->assertHasErrors(['newSlug']);

    expect($this->asso->fresh()->slug)->toBe('ancien-slug');
});

it('rejects slug with special characters', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'slug_avec_underscore!')
        ->call('saveSlug')
        ->assertHasErrors(['newSlug']);

    expect($this->asso->fresh()->slug)->toBe('ancien-slug');
});

// --- Validation : unicité ---

it('rejects slug already used by another association', function () {
    Association::factory()->create(['slug' => 'slug-pris']);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'slug-pris')
        ->call('saveSlug')
        ->assertHasErrors(['newSlug']);

    expect($this->asso->fresh()->slug)->toBe('ancien-slug');
});

// --- Slug identique : no-op ---

it('no-op when the new slug is identical to the current one', function () {
    $countBefore = SuperAdminAccessLog::where('association_id', $this->asso->id)->count();

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'ancien-slug')
        ->call('saveSlug')
        ->assertSet('editingSlug', false)
        ->assertHasNoErrors();

    expect($this->asso->fresh()->slug)->toBe('ancien-slug');
    expect(SuperAdminAccessLog::where('association_id', $this->asso->id)->count())->toBe($countBefore);
});

// --- Validation : longueur max ---

it('rejects slug longer than 80 characters', function () {
    $longSlug = str_repeat('a', 81);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', $longSlug)
        ->call('saveSlug')
        ->assertHasErrors(['newSlug']);

    expect($this->asso->fresh()->slug)->toBe('ancien-slug');
});

// --- Policy : non super-admin ---

it('aborts 403 when a non-super-admin calls saveSlug', function () {
    $regularUser = User::factory()->create(['role_systeme' => RoleSysteme::User]);

    Livewire::actingAs($regularUser)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->set('newSlug', 'hacked-slug')
        ->call('saveSlug')
        ->assertStatus(403);

    expect($this->asso->fresh()->slug)->toBe('ancien-slug');
});

// --- allowSlugChange flag : regression guard ---

it('the allowSlugChange flag is set so the observer lets the update through', function () {
    // If allowSlugChange is not set, ImmutableSlugObserver throws SlugImmutableException.
    // A successful slug change proves the flag was correctly set before save().
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'flag-was-set')
        ->call('saveSlug')
        ->assertHasNoErrors();

    expect($this->asso->fresh()->slug)->toBe('flag-was-set');
});

// --- Vue : rendu ---

it('vue par défaut affiche le bouton Modifier et pas la modale ouverte', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->assertSee('Modifier')
        ->assertDontSee('Modifier le slug');
});

it('après openSlugEditor la vue contient le titre de la modale et l\'input bindé', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->assertSee('Modifier le slug')
        ->assertSeeHtml('wire:model="newSlug"');
});

it('flash succès affiché après un saveSlug réussi', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'slug-flash-test')
        ->call('saveSlug')
        ->assertSee('Slug mis à jour.');
});
