<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\TiersQuickView;
use App\Models\Association;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

function setupAssoUser17(): array
{
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    return [$asso, $user];
}

it('affiche la liste des dons d\'un tiers avec bouton télécharger', function () {
    [$asso, $user] = setupAssoUser17();
    $ligne = $this->ligneDonValide();
    $tiersId = $ligne->transaction->tiers_id;

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiersId)
        ->assertSeeText('Télécharger reçu fiscal');
});

it('affiche le numéro du reçu déjà émis', function () {
    [$asso, $user] = setupAssoUser17();
    $ligne = $this->ligneDonValide();
    $tiersId = $ligne->transaction->tiers_id;

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne, $user);

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiersId)
        ->assertSeeText($recu->numero);
});

it('annule + ré-émet un reçu via la modale', function () {
    [$asso, $user] = setupAssoUser17();
    $ligne = $this->ligneDonValide();
    $tiersId = $ligne->transaction->tiers_id;

    $ancien = app(\App\Services\RecuFiscalService::class)->obtenirOuGenerer($ligne, $user);

    \Livewire\Livewire::actingAs($user)->test(\App\Livewire\TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiersId)
        ->call('ouvrirModaleAnnulation', $ancien->id)
        ->set('motifAnnulation', 'Adresse corrigée')
        ->call('confirmerReEmission');

    $ancien->refresh();
    expect($ancien->isAnnule())->toBeTrue();
    expect($ancien->remplace_par_id)->not->toBeNull();
});
