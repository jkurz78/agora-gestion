<?php

declare(strict_types=1);

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Association;
use App\Models\Compte;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/*
 * Portée des exercices sur l'écran du rapport par opérations.
 *
 * ⚠️ Horloge de test gelée au 2026-01-15 (tests/Pest.php) : l'exercice
 * affiché est 2025, du 2025-09-01 au 2026-08-31. Les fixtures sont datées
 * franchement à l'intérieur des fenêtres visées, jamais sur une borne.
 */

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);

    $tenantId = (int) TenantContext::currentId();

    $this->compteCharge = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '606', 'intitule' => 'Achats',
        'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
    $this->compteProduit = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '706', 'intitule' => 'Prestations',
        'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);

    $type = TypeOperation::factory()->create([
        'association_id' => $tenantId,
        'compte_id' => $this->compteProduit->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $tenantId,
        'nom' => 'Op multi-exercices',
        'type_operation_id' => $type->id,
    ]);

    $this->tiers = Tiers::factory()->create([
        'association_id' => $tenantId,
        'type' => 'particulier',
        'nom' => 'dupont',
        'prenom' => 'Jean',
    ]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

/** Dépense réelle imputée sur l'opération du test, portée par le tiers du test. */
function porteeCrOpsDepense(int $compteId, int $operationId, float $montant, string $date, int $seance = 1): TransactionLigne
{
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => (int) TenantContext::currentId(),
        'date' => $date,
        'saisi_par' => auth()->id(),
        'tiers_id' => test()->tiers->id,
    ]);
    $tx->lignes()->forceDelete();

    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => $montant,
        'compte_id' => $compteId,
        'operation_id' => $operationId,
        'seance' => $seance,
        'debit' => $montant,
        'credit' => 0.0,
    ]);
}

/** Prévision d'encadrement rattachée à une séance, datée ou non. */
function porteeCrOpsPrevision(int $compteId, int $operationId, float $montant, ?string $dateSeance, int $numero): void
{
    $seance = Seance::create([
        'association_id' => (int) TenantContext::currentId(),
        'operation_id' => $operationId,
        'numero' => $numero,
        'date' => $dateSeance,
    ]);

    EncadrementPrevision::create([
        'association_id' => (int) TenantContext::currentId(),
        'operation_id' => $operationId,
        'tiers_id' => test()->tiers->id,
        'seance_id' => $seance->id,
        'compte_id' => $compteId,
        'montant_prevu' => $montant,
    ]);
}

it('porteeExercices vaut current par défaut et le total ne couvre que l\'exercice affiché', function () {
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSet('porteeExercices', 'current')
        ->set('selectedOperationIds', [$this->operation->id])
        ->assertViewHas('totalCharges', 100.0);
});

it('en portée all le total additionne tous les exercices', function () {
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$this->operation->id])
        ->set('porteeExercices', 'all')
        ->assertViewHas('totalCharges', 150.0);
});

it('une valeur de portée inconnue retombe sur current', function () {
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    // Note : on ne peut pas fiabiliser cette preuve via assertViewHas('porteeExercices', …) —
    // Livewire réinjecte automatiquement les propriétés publiques du composant dans les
    // données de la vue APRÈS le tableau explicite renvoyé par render() (Utils::generateBladeView
    // fait un View::with($properties) qui écrase toute clé de même nom). Comme la clé de vue
    // 'porteeExercices' porte le même nom que la propriété, elle est réécrasée par la valeur brute
    // non normalisée. Sans incidence sur le comportement réel : les deux preuves comportementales
    // ci-dessous (total calculé + absence du rendu « all ») passent par $portee, la variable locale
    // de render(), jamais par cette clé de vue piégée.
    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$this->operation->id])
        ->set('porteeExercices', 'n-importe-quoi')
        ->assertViewHas('totalCharges', 100.0)
        ->assertDontSee('2025-2026');
});

it('exportUrl contient exercices=all', function () {
    $url = Livewire::test(RapportCompteResultatOperations::class)
        ->set('porteeExercices', 'all')
        ->instance()
        ->exportUrl('xlsx');

    expect($url)->toContain('exercices=all');
});

it('la vue expose exercices avec les années attendues, triées', function () {
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    $exercices = Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$this->operation->id])
        ->set('porteeExercices', 'all')
        ->viewData('exercices');

    expect(collect($exercices)->pluck('annee')->all())->toBe([2025, 2024]);
});

it('le rendu all affiche une ligne par exercice sous le compte, avec les libellés', function () {
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$this->operation->id])
        ->set('porteeExercices', 'all')
        ->assertSee('2025-2026')
        ->assertSee('2024-2025');
});

it('le rendu current n\'affiche aucun libellé d\'exercice — non-régression', function () {
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$this->operation->id])
        ->assertDontSee('2025-2026')
        ->assertDontSee('2024-2025');
});

it('avec parTiers, l\'ordre est Compte → Exercice → Tiers', function () {
    porteeCrOpsDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$this->operation->id])
        ->set('parTiers', true)
        ->set('porteeExercices', 'all')
        ->assertSeeInOrder(['Achats', '2025-2026', 'DUPONT']);
});

it('le contrôle de portée est présent dans la barre de filtres', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSeeHtml('wire:model.live="porteeExercices"')
        ->assertSeeHtml('Tous les exercices');
});

