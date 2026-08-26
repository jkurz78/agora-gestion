<?php

declare(strict_types=1);

use App\Livewire\PlanComptable;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Anomalie 1 (audit) — PlanComptable::render() liste déjà les comptes de
 * classe 2 (whereIn('classe', [2, 6, 7])), mais la création, la
 * renumérotation et le renommage de famille restaient câblés sur 6/7
 * uniquement : impossible de créer un compte d'immobilisation
 * supplémentaire ou de gérer les familles 21/28. Ce fichier couvre
 * l'ouverture de ces trois opérations à la classe 2.
 *
 * La garde contre la renumérotation d'un compte référencé par une fiche
 * d'immobilisation vit dans PlanComptableGuardTest.php, à côté de sa garde
 * de suppression jumelle (même contexte : ImmobilisationComptesSeeder +
 * fiche acquise).
 */
function b1CreerCompteClasse2(string $numero, string $intitule): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numero,
        'intitule' => $intitule,
        'classe' => (int) substr($numero, 0, 1),
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);
}

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);
});

// ── Création ──────────────────────────────────────────────────────

it('crée un compte de classe 2 et son compte d’amortissement 28X', function (): void {
    Livewire::test(PlanComptable::class)
        ->call('openCreate')
        ->set('numero_pcg', '2154')
        ->set('intitule', 'Matériel')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    Livewire::test(PlanComptable::class)
        ->call('openCreate')
        ->set('numero_pcg', '28154')
        ->set('intitule', 'Amortissements du matériel')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $compte = Compte::where('numero_pcg', '2154')->first();
    $compteAmortissement = Compte::where('numero_pcg', '28154')->first();

    expect($compte)->not->toBeNull()
        ->and($compte->classe)->toBe(2)
        ->and($compteAmortissement)->not->toBeNull()
        ->and($compteAmortissement->classe)->toBe(2);
});

it('rejette un numéro de classe 2 mal formé', function (): void {
    Livewire::test(PlanComptable::class)
        ->call('openCreate')
        ->set('numero_pcg', '2A')
        ->set('intitule', 'Intitulé test')
        ->call('save')
        ->assertHasErrors(['numero_pcg' => 'regex'])
        ->assertSee('Le numéro doit commencer par 2, 6 ou 7');

    expect(Compte::where('intitule', 'Intitulé test')->exists())->toBeFalse();
});

// ── Renumérotation ───────────────────────────────────────────────

it('renumérote un compte de classe 2 non utilisé par une fiche', function (): void {
    $compte = b1CreerCompteClasse2('2154', 'Matériel');

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '2155')
        ->assertReturned(true);

    expect($compte->fresh()->numero_pcg)->toBe('2155');
});

it('refuse un changement de classe à la renumérotation d’un compte de classe 2', function (): void {
    $compte = b1CreerCompteClasse2('2154', 'Matériel');

    Livewire::test(PlanComptable::class)
        ->call('updateField', $compte->id, 'numero_pcg', '658')
        ->assertSet('flashType', 'danger')
        ->assertReturned(false);

    expect($compte->fresh()->numero_pcg)->toBe('2154');
});

// ── Renommage de famille ─────────────────────────────────────────

it('renomme les familles 21 et 28 de la classe 2', function (): void {
    Famille::factory()->create(['association_id' => TenantContext::currentId(), 'code' => '21', 'nom' => '21']);
    Famille::factory()->create(['association_id' => TenantContext::currentId(), 'code' => '28', 'nom' => '28']);

    Livewire::test(PlanComptable::class)->call('updateFamilleNom', '21', 'Immobilisations corporelles');
    expect(Famille::where('code', '21')->first()->nom)->toBe('Immobilisations corporelles');

    Livewire::test(PlanComptable::class)->call('updateFamilleNom', '28', 'Amortissements des immobilisations');
    expect(Famille::where('code', '28')->first()->nom)->toBe('Amortissements des immobilisations');
});

it('matérialise la famille orpheline d’un préfixe de classe 2 à la volée', function (): void {
    expect(Famille::where('code', '21')->exists())->toBeFalse();

    Livewire::test(PlanComptable::class)->call('updateFamilleNom', '21', 'Immobilisations corporelles');

    expect(Famille::where('code', '21')->first()->nom)->toBe('Immobilisations corporelles');
});

// ── Aide utilisateur ──────────────────────────────────────────────

it('mentionne la classe 2 dans l’aide de la modale de création', function (): void {
    Livewire::test(PlanComptable::class)
        ->call('openCreate')
        ->assertSee('immobilisation');
});
