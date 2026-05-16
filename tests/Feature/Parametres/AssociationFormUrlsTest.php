<?php

declare(strict_types=1);

use App\Livewire\Parametres\AssociationForm;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create([
        'url_site_web' => 'https://monasso.fr',
        'url_renouvellement_adhesion' => 'https://helloasso.com/adhesion',
        'url_nouveau_don' => 'https://helloasso.com/don',
    ]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Affichage formulaire — les 3 champs URL sont présents
// ─────────────────────────────────────────────────────────────────────────────
it('affiche les 3 champs URL avec les valeurs actuelles de l\'association', function () {
    Livewire::test(AssociationForm::class)
        ->assertSet('url_site_web', 'https://monasso.fr')
        ->assertSet('url_renouvellement_adhesion', 'https://helloasso.com/adhesion')
        ->assertSet('url_nouveau_don', 'https://helloasso.com/don')
        ->assertSeeHtml('wire:model="url_site_web"')
        ->assertSeeHtml('wire:model="url_renouvellement_adhesion"')
        ->assertSeeHtml('wire:model="url_nouveau_don"');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Save — les 3 URLs sont persistées en base
// ─────────────────────────────────────────────────────────────────────────────
it('persiste les 3 URLs après save', function () {
    $this->association->update([
        'url_site_web' => null,
        'url_renouvellement_adhesion' => null,
        'url_nouveau_don' => null,
    ]);

    Livewire::test(AssociationForm::class)
        ->set('url_site_web', 'https://monasso.fr')
        ->set('url_renouvellement_adhesion', 'https://helloasso.com/adhesion-2026')
        ->set('url_nouveau_don', 'https://helloasso.com/don-2026')
        ->call('save');

    $this->association->refresh();
    expect($this->association->url_site_web)->toBe('https://monasso.fr');
    expect($this->association->url_renouvellement_adhesion)->toBe('https://helloasso.com/adhesion-2026');
    expect($this->association->url_nouveau_don)->toBe('https://helloasso.com/don-2026');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Validation — URL malformée déclenche une erreur
// ─────────────────────────────────────────────────────────────────────────────
it('valide que url_site_web doit être une URL valide', function () {
    Livewire::test(AssociationForm::class)
        ->set('url_site_web', 'pas-une-url')
        ->call('save')
        ->assertHasErrors(['url_site_web' => 'url']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Nullable — null accepté sans erreur
// ─────────────────────────────────────────────────────────────────────────────
it('accepte des valeurs null pour les 3 champs URL', function () {
    Livewire::test(AssociationForm::class)
        ->set('url_site_web', null)
        ->set('url_renouvellement_adhesion', null)
        ->set('url_nouveau_don', null)
        ->call('save')
        ->assertHasNoErrors(['url_site_web', 'url_renouvellement_adhesion', 'url_nouveau_don']);

    $this->association->refresh();
    expect($this->association->url_site_web)->toBeNull();
    expect($this->association->url_renouvellement_adhesion)->toBeNull();
    expect($this->association->url_nouveau_don)->toBeNull();
});
