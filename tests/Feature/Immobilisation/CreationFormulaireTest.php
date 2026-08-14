<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('crée une immobilisation depuis la modale', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', '20 tenues d’escrime')
        ->set('quantite', 20)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '3000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();
    expect($immo->numero)->toBe('IM00001')
        ->and($immo->libelle)->toBe('20 tenues d’escrime')
        ->and((int) $immo->compte_amortissement_id)->toBe((int) Compte::ofNumero('28188')->id);
});

it('pré-remplit le compte d’amortissement dérivé du compte choisi', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('compte_id', (string) Compte::ofNumero('2154')->id)
        ->assertSet('compte_amortissement_id', (string) Compte::ofNumero('28154')->id);
});

it('refuse une mise en service antérieure à l’exercice d’acquisition', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '1000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-06-01')
        ->set('duree_mois', 36)
        ->call('enregistrer')
        ->assertHasErrors('date_mise_en_service');

    expect(Immobilisation::count())->toBe(0);
});
