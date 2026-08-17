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
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Rapports\CompteResultatBuilder;
use App\Tenant\TenantContext;

/*
 * La dimension exercice suit le calendrier DU TENANT.
 *
 * Le mois de début d'exercice est un réglage de l'association
 * (`exercice_mois_debut`, 9 par défaut). Toute la ventilation par exercice
 * passe par ExerciceService — jamais par une expression de date écrite en SQL,
 * qui figerait septembre et donnerait des montants faux, en silence, à une
 * association en exercice civil ou décalé.
 *
 * Ces tests montent leur propre tenant et ne dépendent donc pas de l'exercice
 * affiché par défaut : ils passent l'année explicitement au builder.
 */

/**
 * Monte un tenant avec le mois de début d'exercice demandé et retourne son
 * contexte de travail.
 *
 * @return array{0: Association, 1: User, 2: Compte, 3: Operation, 4: Tiers}
 */
function calendrierTenant(int $moisDebut): array
{
    $assoc = Association::factory()->create(['exercice_mois_debut' => $moisDebut]);
    TenantContext::boot($assoc);

    $user = User::factory()->create();
    $user->associations()->attach($assoc->id, ['role' => 'admin', 'joined_at' => now()]);
    test()->actingAs($user);

    SystemeSeeder::seed();

    $compteCharge = Compte::create([
        'association_id' => $assoc->id, 'numero_pcg' => '606', 'intitule' => 'Achats',
        'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
    $compteProduit = Compte::create([
        'association_id' => $assoc->id, 'numero_pcg' => '706', 'intitule' => 'Prestations',
        'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);

    $type = TypeOperation::factory()->create([
        'association_id' => $assoc->id,
        'compte_id' => $compteProduit->id,
    ]);
    $operation = Operation::factory()->create([
        'association_id' => $assoc->id,
        'nom' => 'Op calendrier',
        'type_operation_id' => $type->id,
    ]);

    $intervenant = Tiers::factory()->create([
        'association_id' => $assoc->id,
        'nom' => 'FORMATEUR',
        'prenom' => 'Alex',
    ]);

    return [$assoc, $user, $compteCharge, $operation, $intervenant];
}

/** Dépense réelle datée, sur le tenant courant. */
function calendrierDepense(
    Association $assoc,
    User $user,
    Compte $compte,
    Operation $operation,
    Tiers $tiers,
    string $date,
    float $montant,
): void {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $assoc->id,
        'date' => $date,
        'saisi_par' => $user->id,
        'tiers_id' => $tiers->id,
    ]);
    $tx->lignes()->forceDelete();

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => $montant,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'seance' => 1,
        'debit' => $montant,
        'credit' => 0.0,
    ]);
}

afterEach(function () {
    TenantContext::clear();
});

it('rattache les montants selon un calendrier civil', function () {
    // exercice_mois_debut = 1 → exercice 2025 = du 2025-01-01 au 2025-12-31.
    [$assoc, $user, $compte, $operation, $tiers] = calendrierTenant(1);

    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2025-03-15', 100.0);
    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2024-11-20', 50.0);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        2025, [(int) $operation->id],
        portee: PorteeExercices::Tous,
    );

    // Le 2024-11-20 tombe dans l'exercice 2024 en calendrier civil, alors qu'il
    // tomberait dans l'exercice 2024 aussi en septembre — c'est le LIBELLÉ qui
    // distingue les deux calendriers, et le rattachement du 2025-03-15.
    expect(collect($data['exercices'])->pluck('annee')->all())->toBe([2025, 2024])
        ->and($data['exercices'][0]['label'])->toBe('2025')
        ->and($data['exercices'][1]['label'])->toBe('2024');

    $compteNoeud = $data['charges'][0]['comptes'][0];
    expect(collect($compteNoeud['exercices'])->firstWhere('annee', 2025)['montant'])->toBe(100.0)
        ->and(collect($compteNoeud['exercices'])->firstWhere('annee', 2024)['montant'])->toBe(50.0);
});

it('sépare en calendrier civil deux dates que septembre regrouperait', function () {
    // C'est le test qui distingue réellement les deux calendriers : en civil,
    // 2025-03-15 et 2025-10-15 sont dans le MÊME exercice (2025) ; avec un
    // début en septembre, ils seraient dans deux exercices différents
    // (2024 et 2025).
    [$assoc, $user, $compte, $operation, $tiers] = calendrierTenant(1);

    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2025-03-15', 100.0);
    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2025-10-15', 30.0);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        2025, [(int) $operation->id],
        portee: PorteeExercices::Tous,
    );

    expect(collect($data['exercices'])->pluck('annee')->all())->toBe([2025])
        ->and($data['charges'][0]['comptes'][0]['montant_exercices'])->toBe(130.0);
});

it('rattache les montants selon un calendrier décalé en mars', function () {
    // exercice_mois_debut = 3 → exercice 2025 = du 2025-03-01 au 2026-02-28.
    [$assoc, $user, $compte, $operation, $tiers] = calendrierTenant(3);

    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2026-02-01', 100.0);
    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2025-02-01', 50.0);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        2025, [(int) $operation->id],
        portee: PorteeExercices::Tous,
    );

    // Le 2026-02-01 appartient à l'exercice 2025 (il court jusqu'au 2026-02-28),
    // le 2025-02-01 à l'exercice 2024. Un calcul figé sur septembre les
    // rattacherait tous deux à 2025 et 2024 différemment.
    expect(collect($data['exercices'])->pluck('annee')->all())->toBe([2025, 2024])
        ->and($data['exercices'][0]['label'])->toBe('2025-2026');

    $compteNoeud = $data['charges'][0]['comptes'][0];
    expect(collect($compteNoeud['exercices'])->firstWhere('annee', 2025)['montant'])->toBe(100.0)
        ->and(collect($compteNoeud['exercices'])->firstWhere('annee', 2024)['montant'])->toBe(50.0);
});

it('borne la portée courante sur le calendrier du tenant, pas sur septembre', function () {
    [$assoc, $user, $compte, $operation, $tiers] = calendrierTenant(3);

    // Dans l'exercice 2025 décalé (2025-03-01 → 2026-02-28).
    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2025-04-10', 100.0);
    // Hors : appartient à l'exercice 2024 décalé.
    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2025-01-10', 999.0);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        2025, [(int) $operation->id],
    );

    expect(collect($data['charges'])->sum('montant'))->toBe(100.0);
});

it('rattache une prévision selon le calendrier du tenant', function () {
    [$assoc, $user, $compte, $operation, $tiers] = calendrierTenant(1);

    $seance = Seance::create([
        'association_id' => $assoc->id,
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2027-06-15',
    ]);
    EncadrementPrevision::create([
        'association_id' => $assoc->id,
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'seance_id' => $seance->id,
        'compte_id' => $compte->id,
        'montant_prevu' => 300.0,
    ]);
    calendrierDepense($assoc, $user, $compte, $operation, $tiers, '2025-03-15', 100.0);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        2025, [(int) $operation->id],
        previsionnel: true,
        portee: PorteeExercices::Tous,
    );

    // En calendrier civil, juin 2027 est l'exercice 2027 — pas 2026 comme le
    // donnerait un rattachement figé sur septembre.
    expect(collect($data['exercices'])->pluck('annee')->all())->toBe([2027, 2025])
        ->and(collect($data['exercices'])->firstWhere('annee', 2027)['label'])->toBe('2027');
});
