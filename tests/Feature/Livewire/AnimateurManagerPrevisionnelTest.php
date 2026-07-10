<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Livewire\AnimateurManager;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    SystemeSeeder::seed();

    $this->operation = Operation::factory()->create();
    $this->seance1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);
    $this->seance2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2, 'date' => now()->addDays(7)]);
    $this->seance3 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 3, 'date' => now()->addDays(14)]);

    $this->compte1 = Compte::factory()->numero('606')->create(['intitule' => 'Encadrement']);
    $this->compte2 = Compte::factory()->numero('625')->create(['intitule' => 'Frais déplacement']);

    $this->tiers = Tiers::factory()->pourDepenses()->create(['nom' => 'DURAND', 'prenom' => 'Sophie']);
});

it('ajoute un encadrant en créant une 1re ligne prévision à 0', function (): void {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('ajouterEncadrantAvecCompte', $this->tiers->id, $this->compte1->id);

    // Bridge DC-7 : le miroir SousCategorie de compte1 (code_cerfa = numero_pcg) doit
    // être posé en sous_categorie_id par SyncCompteDepuisSousCategorie.
    $sc1 = SousCategorie::where('association_id', $this->association->id)
        ->where('code_cerfa', $this->compte1->numero_pcg)
        ->firstOrFail();

    expect(EncadrementPrevision::count())->toBe(1)
        ->and((float) EncadrementPrevision::first()->montant_prevu)->toBe(0.0)
        ->and((int) EncadrementPrevision::first()->tiers_id)->toBe((int) $this->tiers->id)
        ->and((int) EncadrementPrevision::first()->compte_id)->toBe((int) $this->compte1->id)
        ->and((int) EncadrementPrevision::first()->sous_categorie_id)->toBe((int) $sc1->id)
        ->and((int) EncadrementPrevision::first()->seance_id)->toBe((int) $this->seance1->id);
});

it('ajoute une 2e ligne sous-catégorie sur un encadrant existant', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte1->id,
        'seance_id' => $this->seance1->id,
        'montant_prevu' => 100,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('ajouterLigneCompte', $this->tiers->id, $this->compte2->id);

    expect(EncadrementPrevision::where('compte_id', $this->compte2->id)->count())->toBe(1);
});

it('met à jour le montant prévu d\'une cellule (upsert)', function (): void {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('ajouterEncadrantAvecCompte', $this->tiers->id, $this->compte1->id)
        ->call('updateMontantPrevu', $this->tiers->id, $this->compte1->id, $this->seance2->id, '85,50');

    $cellule = EncadrementPrevision::where('seance_id', $this->seance2->id)->first();
    expect($cellule)->not->toBeNull()
        ->and((float) $cellule->montant_prevu)->toBe(85.50);
});

it('recopie le montant de la 1re séance sur les autres', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte1->id,
        'seance_id' => $this->seance1->id,
        'montant_prevu' => 200,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('recopierLigne', $this->tiers->id, $this->compte1->id);

    expect((float) EncadrementPrevision::where('seance_id', $this->seance2->id)->first()->montant_prevu)->toBe(200.0)
        ->and((float) EncadrementPrevision::where('seance_id', $this->seance3->id)->first()->montant_prevu)->toBe(200.0);
});

it('supprime une ligne sous-catégorie sans réalisé', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte1->id,
        'seance_id' => $this->seance1->id,
        'montant_prevu' => 100,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('supprimerLigne', $this->tiers->id, $this->compte1->id);

    expect(EncadrementPrevision::count())->toBe(0);
});

it('refuse de supprimer une ligne qui a un réalisé', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte1->id,
        'seance_id' => $this->seance1->id,
        'montant_prevu' => 100,
    ]);
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->tiers->id,
        'compte_id' => CompteBancaire::factory()->create()->id,
        'date' => now(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte1->id,
        'debit' => 50,
        'credit' => 0,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'montant' => 50,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('supprimerLigne', $this->tiers->id, $this->compte1->id)
        ->assertHasErrors(['supprimerLigne']);

    expect(EncadrementPrevision::count())->toBe(1);
});

it('supprime un encadrant sans réalisé (cascade prévisions)', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte1->id,
        'seance_id' => $this->seance1->id,
        'montant_prevu' => 100,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('supprimerEncadrant', $this->tiers->id);

    expect(EncadrementPrevision::count())->toBe(0);
});

it('refuse de créer une prévision sur une séance d\'une autre opération', function (): void {
    $autreOp = Operation::factory()->create();
    $seanceAutre = Seance::create(['operation_id' => $autreOp->id, 'numero' => 1, 'date' => now()]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('updateMontantPrevu', $this->tiers->id, $this->compte1->id, $seanceAutre->id, '50');

    expect(EncadrementPrevision::where('seance_id', $seanceAutre->id)->count())->toBe(0);
});
