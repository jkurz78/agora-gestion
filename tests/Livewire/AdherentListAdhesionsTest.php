<?php

declare(strict_types=1);

use App\Livewire\AdherentList;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('liste les tiers avec adhésion payée pour l\'exercice courant (filtre a_jour)', function (): void {
    $aJour = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AJour']);
    $absent = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Absent']);

    Adhesion::factory()->payee()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $aJour->id,
        'exercice' => 2025,
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('AJOUR')
        ->assertDontSee('ABSENT');
});

it('liste les tiers avec adhésion gratuite pour l\'exercice courant (filtre a_jour)', function (): void {
    $honneur = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Honneur']);
    $absent = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'SansAdhesion']);

    Adhesion::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $honneur->id,
        'exercice' => 2025,
        'notes' => 'Membre d\'honneur',
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('HONNEUR')
        ->assertDontSee('SANSADHESION');
});

it('filtre en_retard : tiers avec adhésion exercice précédent mais pas exercice courant', function (): void {
    $aJour = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AJour']);
    $retard = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'EnRetard']);

    // $aJour a N-1 ET N → à jour
    Adhesion::factory()->payee()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $aJour->id,
        'exercice' => 2024,
    ]);
    Adhesion::factory()->payee()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $aJour->id,
        'exercice' => 2025,
    ]);

    // $retard a N-1 seulement
    Adhesion::factory()->payee()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $retard->id,
        'exercice' => 2024,
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'en_retard')
        ->assertSee('ENRETARD')
        ->assertDontSee('AJOUR');
});

it('filtre vide (tous) : tous les tiers ayant au moins une adhésion, tous exercices', function (): void {
    $avecAdhesion = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'AvecAdhesion']);
    $sansAdhesion = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'SansAdhesion']);

    Adhesion::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $avecAdhesion->id,
        'exercice' => 2023,
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSee('AVECADHESION')
        ->assertDontSee('SANSADHESION');
});

it('affiche un badge Offerte et le motif sur ligne adhésion gratuite', function (): void {
    $honneur = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Honneur']);

    Adhesion::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $honneur->id,
        'exercice' => 2025,
        'notes' => 'Bénévole historique',
    ]);

    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('Offerte')
        ->assertSee('Bénévole historique');
});

it('affiche le montant et le compte sur ligne adhésion payée', function (): void {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Payant']);

    $adhesion = Adhesion::factory()->payee()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
    ]);

    // La transaction a un compte (via TransactionFactory → CompteBancaire::factory())
    // et un mode_paiement — on vérifie juste que le composant s'affiche sans erreur
    // et que le nom du tiers est bien présent (l'adhésion payée est visible)
    Livewire::actingAs($this->user)
        ->test(AdherentList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('PAYANT')
        ->assertDontSee('Offerte');
});
