<?php

declare(strict_types=1);

use App\Enums\PorteeExercices;
use App\Models\Association;
use App\Models\Compte;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Rapports\CompteResultatBuilder;
use App\Tenant\TenantContext;

/*
 * Portée « exercice affiché » contre « tous les exercices ».
 *
 * ⚠️ Horloge de test gelée au 2026-01-15 (tests/Pest.php) : l'exercice affiché
 * est 2025, du 2025-09-01 au 2026-08-31. Les fixtures sont datées franchement à
 * l'intérieur des fenêtres visées, jamais sur une borne.
 */

beforeEach(function () {
    $assoc = Association::factory()->create();
    TenantContext::boot($assoc);
    $this->association = $assoc;

    $this->user = User::factory()->create();
    $this->user->associations()->attach($assoc->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();

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

    // Opération datée sur un exercice ancien : ses dates ne doivent avoir
    // aucun effet sur le rattachement de ses montants.
    $this->operation = Operation::factory()->create([
        'association_id' => $tenantId,
        'nom' => 'Op pluriannuelle',
        'date_debut' => '2019-09-01',
        'date_fin' => '2020-08-31',
        'type_operation_id' => $type->id,
    ]);

    $this->intervenant = Tiers::factory()->create([
        'association_id' => $tenantId,
        'nom' => 'FORMATEUR',
        'prenom' => 'Alex',
    ]);

    $this->builder = app(CompteResultatBuilder::class);
});

afterEach(function () {
    TenantContext::clear();
});

/** Dépense réelle imputée sur l'opération du test. */
function porteeDepense(int $compteId, int $operationId, float $montant, string $date, int $seance = 1): TransactionLigne
{
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => (int) TenantContext::currentId(),
        'date' => $date,
        'saisi_par' => auth()->id(),
        'tiers_id' => test()->intervenant->id,
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
function porteePrevision(int $compteId, int $operationId, float $montant, ?string $dateSeance, int $numero): void
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
        'tiers_id' => test()->intervenant->id,
        'seance_id' => $seance->id,
        'compte_id' => $compteId,
        'montant_prevu' => $montant,
    ]);
}

/** @param array<string, mixed> $extra */
function porteeRapport(array $extra = []): array
{
    return test()->builder->compteDeResultatOperations(
        2025,
        [(int) test()->operation->id],
        parSeances: $extra['parSeances'] ?? false,
        parTiers: $extra['parTiers'] ?? false,
        previsionnel: $extra['previsionnel'] ?? false,
        parOperations: $extra['parOperations'] ?? false,
        portee: $extra['portee'] ?? PorteeExercices::Courant,
    );
}

it('par défaut ne calcule que les montants de l\'exercice affiché', function () {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    expect(collect(porteeRapport()['charges'])->sum('montant'))->toBe(100.0);
});

it('en portée all lit tous les exercices, du plus récent au plus ancien', function () {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    $data = porteeRapport(['portee' => PorteeExercices::Tous]);

    expect(collect($data['exercices'])->pluck('annee')->all())->toBe([2025, 2024]);

    $compte = $data['charges'][0]['comptes'][0];
    expect(collect($compte['exercices'])->pluck('annee')->all())->toBe([2025, 2024])
        ->and(collect($compte['exercices'])->firstWhere('annee', 2025)['montant'])->toBe(100.0)
        ->and(collect($compte['exercices'])->firstWhere('annee', 2024)['montant'])->toBe(50.0)
        ->and($compte['montant_exercices'])->toBe(150.0);
});

it('rattache un montant à la date de transaction, pas aux dates de l\'opération', function () {
    // L'opération est datée 2019-2020 : aucune influence attendue.
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 80.0, '2026-01-10');

    $data = porteeRapport(['portee' => PorteeExercices::Tous]);

    expect(collect($data['exercices'])->pluck('annee')->all())->toBe([2025]);
});

it('n\'ajoute aucun exercice sans montant', function () {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');

    expect(porteeRapport(['portee' => PorteeExercices::Tous])['exercices'])->toHaveCount(1);
});

it('libelle les exercices via ExerciceService', function () {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');

    expect(porteeRapport(['portee' => PorteeExercices::Tous])['exercices'][0]['label'])->toBe('2025-2026');
});

it('en projection ajoute un exercice qui ne porte qu\'une prévision, pas en réalisé', function () {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteePrevision((int) $this->compteCharge->id, (int) $this->operation->id, 300.0, '2027-10-10', 3);

    $projection = porteeRapport(['portee' => PorteeExercices::Tous, 'previsionnel' => true]);
    $realise = porteeRapport(['portee' => PorteeExercices::Tous]);

    expect(collect($projection['exercices'])->pluck('annee')->all())->toBe([2027, 2025])
        ->and(collect($realise['exercices'])->pluck('annee')->all())->toBe([2025]);
});

it('regroupe les prévisions de séance non datée sous Exercice non déterminé, en dernier', function () {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteePrevision((int) $this->compteCharge->id, (int) $this->operation->id, 40.0, null, 9);

    $data = porteeRapport(['portee' => PorteeExercices::Tous, 'previsionnel' => true]);

    expect(collect($data['exercices'])->pluck('annee')->all())->toBe([2025, 0])
        ->and(collect($data['exercices'])->firstWhere('annee', 0)['label'])
        ->toBe('Exercice non déterminé');
});

it('en portée courante une prévision d\'un autre exercice n\'influence pas le projeté', function () {
    porteePrevision((int) $this->compteCharge->id, (int) $this->operation->id, 999.0, '2027-10-10', 3);
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');

    expect(porteeRapport(['previsionnel' => true])['proj_charges']->total())->toBe(100.0);
});

it('ne compte pas deux fois une ligne ventilée en portée all', function () {
    $ligne = porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    $ligne->update(['operation_id' => null]);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'montant' => 60.0,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->operation->id,
        'seance' => 2,
        'montant' => 40.0,
    ]);

    $data = porteeRapport(['portee' => PorteeExercices::Tous]);

    expect($data['charges'][0]['comptes'][0]['montant_exercices'])->toBe(100.0);
});

it('les sommes par exercice égalent les totaux de compte et de famille', function () {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 25.0, '2023-10-10');

    $famille = porteeRapport(['portee' => PorteeExercices::Tous])['charges'][0];
    $compte = $famille['comptes'][0];

    expect(round(collect($compte['exercices'])->sum('montant'), 2))
        ->toBe(round((float) $compte['montant_exercices'], 2))
        ->and(round((float) $famille['montant_exercices'], 2))->toBe(175.0);
});

dataset('axesPortee', [
    'réalisé — aucun axe' => [false, false, false, false],
    'réalisé — séances' => [false, true, false, false],
    'réalisé — tiers' => [false, false, true, false],
    'réalisé — opérations' => [false, false, false, true],
    'réalisé — tous les axes' => [false, true, true, true],
    'projection — aucun axe' => [true, false, false, false],
    'projection — séances + tiers' => [true, true, true, false],
    'projection — tous les axes' => [true, true, true, true],
]);

it('reste cohérent en portée all quelles que soient les combinaisons d\'axes', function (
    bool $previsionnel,
    bool $parSeances,
    bool $parTiers,
    bool $parOperations,
) {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10', 1);
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10', 2);
    porteePrevision((int) $this->compteCharge->id, (int) $this->operation->id, 300.0, '2027-10-10', 3);

    $data = porteeRapport([
        'portee' => PorteeExercices::Tous,
        'previsionnel' => $previsionnel,
        'parSeances' => $parSeances,
        'parTiers' => $parTiers,
        'parOperations' => $parOperations,
    ]);

    expect($data)->toHaveKeys(['charges', 'produits', 'exercices']);

    $attendu = $previsionnel ? [2027, 2025, 2024] : [2025, 2024];
    expect(collect($data['exercices'])->pluck('annee')->all())->toBe($attendu);

    // Invariant central : la somme des exercices égale le total du nœud, à
    // tous les niveaux, quels que soient les axes demandés.
    foreach ($data['charges'] as $famille) {
        $sommeComptes = 0.0;
        foreach ($famille['comptes'] as $compte) {
            expect(round(collect($compte['exercices'])->sum('montant'), 2))
                ->toBe(round((float) $compte['montant_exercices'], 2));
            $sommeComptes += (float) $compte['montant_exercices'];
        }
        expect(round($sommeComptes, 2))->toBe(round((float) $famille['montant_exercices'], 2));
    }
})->with('axesPortee');

it('ne compte le réalisé qu\'une fois par exercice, quels que soient les axes', function (
    bool $previsionnel,
    bool $parSeances,
    bool $parTiers,
    bool $parOperations,
) {
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10', 1);
    porteeDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10', 2);

    $data = porteeRapport([
        'portee' => PorteeExercices::Tous,
        'parSeances' => $parSeances,
        'parTiers' => $parTiers,
        'parOperations' => $parOperations,
    ]);

    expect(round(collect($data['charges'])->sum('montant_exercices'), 2))->toBe(150.0);
})->with('axesPortee');
