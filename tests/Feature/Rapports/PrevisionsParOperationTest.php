<?php

declare(strict_types=1);

// CompteResultatBuilder::previsionsParOperationEtCompte() est la porte étroite
// ouverte pour le rapport « Budget par opérations » : elle délègue à
// fetchPrevisionsFlatEntries() (privée, déjà testée par
// CompteResultatBuilderPrevisionsPlatesTest.php) plutôt que de recopier ses
// jointures. Ce fichier teste donc la couche d'agrégation qui lui est propre :
// le filtrage de l'exercice 0 (séance sans date), le cumul + arrondi, le
// passe-plat RapportService — et, spécifiquement, le scope tenant de la
// source RECETTE (cas 6b), qui n'a pas le même montage que la source charge.
//
// Le OR de date est déjà couvert côté fetchPrevisionsFlatEntries()
// (CompteResultatBuilderPrevisionsPlatesTest.php, cas 4) pour les deux
// sources. Le scope tenant, en revanche, ne l'était que côté CHARGE
// (encadrement_previsions) avant le cas 6b ci-dessous : côté charge,
// `whereIn('ep.operation_id', ...)` et `scopeToCurrentTenant(..., 'ep.association_id')`
// portent sur deux tables distinctes (encadrement_previsions vs son propre
// association_id), donc un scope manquant fuite même quand l'operation_id
// demandé est légitime. Côté recette, `whereIn('p.operation_id', ...)` et
// `scopeToCurrentTenant(..., 'op.association_id')` portent tous deux sur la
// même opération : un scope manquant ne fuite QUE si l'appelant transmet déjà
// l'operation_id d'une autre association — ce que fait précisément le cas 6b.
// Avant son ajout, aucun test (dans ce fichier ni dans
// CompteResultatBuilderPrevisionsPlatesTest.php) ne tenait ce filtre côté
// recette : retirer son ->tap(...) laissait les 215 tests de
// tests/Unit/Services/Rapports, tests/Feature/Rapports, tests/Unit/Tenant et
// tests/Feature/Tenant entièrement verts.

use App\Models\Association;
use App\Models\Compte;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\RapportService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Horloge de test gelée globalement (tests/Pest.php, Carbon::setTestNow) sur
 * 2026-01-15 : l'exercice 2025 est donc borné du 2025-09-01 au 2026-08-31
 * (ExerciceService::dateRange, exercice_mois_debut=9 par défaut sur
 * AssociationFactory). PPOC_DATE_DANS tombe franchement à l'intérieur de
 * cette fenêtre, PPOC_DATE_HORS franchement dans l'exercice précédent.
 */
const PPOC_DATE_DANS = '2025-11-10';

const PPOC_DATE_HORS = '2024-10-15';

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

/**
 * Une opération + son type, prête à recevoir une prévision.
 *
 * @return array{0: Operation, 1: TypeOperation}
 */
function ppocOperation(?Compte $compteType = null): array
{
    $typeOp = TypeOperation::factory()->create($compteType !== null ? ['compte_id' => $compteType->id] : []);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);

    return [$operation, $typeOp];
}

// ---------------------------------------------------------------------------
// 1. Charges — une prévision d'encadrement sur un compte 611B.
// ---------------------------------------------------------------------------

it('agrège une prévision de charge sur le compte de l\'encadrement, à la maille (opération, compte)', function (): void {
    $compte = Compte::factory()->numero('611B')->create(['intitule' => 'Sous-traitance']);
    [$operation] = ppocOperation();
    $tiers = Tiers::factory()->pourDepenses()->create();
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => PPOC_DATE_DANS]);

    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 3750.00,
    ]);

    $resultat = app(CompteResultatBuilder::class)->previsionsParOperationEtCompte(2025, [$operation->id]);

    expect($resultat)->toBe([
        $operation->id => [
            $compte->id => 3750.00,
        ],
    ]);
});

// ---------------------------------------------------------------------------
// 2. Recettes — le compte est celui du TYPE d'opération, jamais un compte
//    porté par le règlement (qui n'en porte structurellement aucun).
// ---------------------------------------------------------------------------

it('agrège une prévision de recette sur le compte du type d\'opération, pas un compte porté par le règlement', function (): void {
    $compteType = Compte::factory()->numero('706')->create(['intitule' => 'Cotisations']);
    [$operation] = ppocOperation($compteType);
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => PPOC_DATE_DANS]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 120.00,
    ]);

    $resultat = app(CompteResultatBuilder::class)->previsionsParOperationEtCompte(2025, [$operation->id]);

    // Sans la jointure type_operations -> comptes, la boucle 'recette' ne
    // pourrait pas produire cette clé de compte : reglements n'a pas de
    // colonne compte_id.
    expect($resultat)->toBe([
        $operation->id => [
            $compteType->id => 120.00,
        ],
    ]);
});

// ---------------------------------------------------------------------------
// 3. Séance sans date — exercice 0, écartée (point de conception de la tâche).
// ---------------------------------------------------------------------------

it('écarte une prévision dont la séance n\'a pas de date (exercice non déterminable)', function (): void {
    $compte = Compte::factory()->numero('611B')->create();
    [$operation] = ppocOperation();
    $tiers = Tiers::factory()->pourDepenses()->create();
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => null]);

    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 90.00,
    ]);

    $resultat = app(CompteResultatBuilder::class)->previsionsParOperationEtCompte(2025, [$operation->id]);

    expect($resultat)->toBe([]);
});

// ---------------------------------------------------------------------------
// 4. Hors exercice — séance datée dans un autre exercice.
// ---------------------------------------------------------------------------

it('écarte une prévision dont la séance est datée dans un autre exercice', function (): void {
    $compte = Compte::factory()->numero('611B')->create();
    [$operation] = ppocOperation();
    $tiers = Tiers::factory()->pourDepenses()->create();
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => PPOC_DATE_HORS]);

    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 150.00,
    ]);

    $resultat = app(CompteResultatBuilder::class)->previsionsParOperationEtCompte(2025, [$operation->id]);

    expect($resultat)->toBe([]);
});

// ---------------------------------------------------------------------------
// 5. Cumul — deux prévisions du même couple (opération, compte) s'additionnent,
//    et le total est arrondi à 2 décimales (10.10 + 20.20 = 30.299999999999997
//    en flottant PHP/SQLite, sans round()).
// ---------------------------------------------------------------------------

it('cumule deux prévisions du même couple (opération, compte) et arrondit le total à 2 décimales', function (): void {
    $compte = Compte::factory()->numero('611B')->create();
    [$operation] = ppocOperation();
    $tiers = Tiers::factory()->pourDepenses()->create();
    $seance1 = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => PPOC_DATE_DANS]);
    $seance2 = Seance::create(['operation_id' => $operation->id, 'numero' => 2, 'date' => PPOC_DATE_DANS]);

    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seance1->id,
        'montant_prevu' => 10.10,
    ]);
    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seance2->id,
        'montant_prevu' => 20.20,
    ]);

    $resultat = app(CompteResultatBuilder::class)->previsionsParOperationEtCompte(2025, [$operation->id]);

    expect($resultat[$operation->id][$compte->id])->toBe(30.3);
});

// ---------------------------------------------------------------------------
// 6a. Isolation tenant (charge) — un test qui tue le filtre précis :
//     l'opération EST dans la liste demandée (le whereIn ne l'écarterait
//     pas), seule la ligne piégée en base appartient à une autre association.
//     Retirer le scope tenant sur ep.association_id ferait fuiter cette
//     ligne ; un test où tout serait chez l'autre association ne le
//     prouverait pas (le whereIn suffirait déjà). Une prévision légitime du
//     tenant courant est incluse pour que le test prouve une vraie requête
//     réussie, pas seulement l'absence de la ligne piégée (cf. §3 du bilan
//     de revue : un tableau vide sans ligne légitime ne prouve rien).
// ---------------------------------------------------------------------------

it('exclut une prévision d\'encadrement d\'une autre association même quand son operation_id figure dans la liste demandée', function (): void {
    $autre = Association::factory()->create();

    $compte = Compte::factory()->numero('611B')->create();
    [$operation] = ppocOperation();
    $tiers = Tiers::factory()->pourDepenses()->create();
    $seanceLegit = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => PPOC_DATE_DANS]);
    $seancePiege = Seance::create(['operation_id' => $operation->id, 'numero' => 2, 'date' => PPOC_DATE_DANS]);

    // Prévision légitime du tenant courant : preuve que la requête rend bien
    // quelque chose, pas seulement qu'elle écarte la ligne piégée.
    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seanceLegit->id,
        'montant_prevu' => 42.00,
    ]);

    // Insertion directe : contourne TenantModel pour forcer une incohérence
    // (association_id de l'autre tenant, operation_id de l'opération courante)
    // qu'aucune écriture applicative normale ne produit, mais que seul le
    // scope tenant explicite sur ep.association_id peut arrêter. Séance
    // distincte de la ligne légitime : la contrainte d'unicité de
    // encadrement_previsions (operation_id, tiers_id, compte_id, seance_id)
    // ne porte pas sur association_id.
    DB::table('encadrement_previsions')->insert([
        'association_id' => $autre->id,
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seancePiege->id,
        'montant_prevu' => 9999.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resultat = app(CompteResultatBuilder::class)->previsionsParOperationEtCompte(2025, [$operation->id]);

    expect($resultat)->toBe([
        $operation->id => [
            $compte->id => 42.00,
        ],
    ]);
});

// ---------------------------------------------------------------------------
// 6b. Isolation tenant (recette) — symétrique du 6a, mais le montage diffère
//     et c'est ce qui rend ce cas nécessaire : côté charge,
//     whereIn('ep.operation_id') et le scope portent sur deux tables
//     distinctes (encadrement_previsions), donc n'importe quelle fuite de
//     tenant se voit. Côté recette, whereIn('p.operation_id') et le scope
//     scopeToCurrentTenant(..., 'op.association_id') portent tous deux sur LA
//     MÊME opération : le filtre ne mord que si l'appelant transmet déjà
//     l'operation_id d'une autre association — exactement ce que fait ce
//     test. Avant ce cas, retirer le ->tap(...) de la branche recette de
//     fetchPrevisionsFlatEntries() ne faisait échouer aucun test de la suite.
// ---------------------------------------------------------------------------

it('exclut une prévision de recette d\'une autre association même quand son operation_id figure dans la liste demandée', function (): void {
    $autre = Association::factory()->create();

    $compteType = Compte::factory()->numero('706')->create(['intitule' => 'Cotisations']);
    [$operation] = ppocOperation($compteType);
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => PPOC_DATE_DANS]);

    // Prévision légitime du tenant courant : preuve que la requête rend bien
    // quelque chose, pas seulement qu'elle écarte l'opération piégée.
    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 55.00,
    ]);

    TenantContext::boot($autre);
    $compteTypeAutre = Compte::factory()->numero('706')->create();
    [$operationAutre] = ppocOperation($compteTypeAutre);
    $tiersAutre = Tiers::factory()->create();
    $participantAutre = Participant::factory()->create([
        'operation_id' => $operationAutre->id,
        'tiers_id' => $tiersAutre->id,
    ]);
    $seanceAutre = Seance::create(['operation_id' => $operationAutre->id, 'numero' => 1, 'date' => PPOC_DATE_DANS]);
    Reglement::create([
        'participant_id' => $participantAutre->id,
        'seance_id' => $seanceAutre->id,
        'montant_prevu' => 9999.00,
    ]);
    TenantContext::boot($this->association);

    // L'appelant transmet l'operation_id de l'AUTRE association : c'est le
    // seul montage où un scope manquant sur op.association_id se verrait,
    // puisque whereIn('p.operation_id') et le scope portent ici sur la même
    // table (operations).
    $resultat = app(CompteResultatBuilder::class)
        ->previsionsParOperationEtCompte(2025, [$operation->id, $operationAutre->id]);

    expect($resultat)->toBe([
        $operation->id => [
            $compteType->id => 55.00,
        ],
    ]);
});

// ---------------------------------------------------------------------------
// 7. Liste vide — rend [] sans toucher la base.
// ---------------------------------------------------------------------------

it('rend un tableau vide sans exécuter de requête quand la liste d\'opérations est vide', function (): void {
    DB::enableQueryLog();

    $resultat = app(CompteResultatBuilder::class)->previsionsParOperationEtCompte(2025, []);

    expect($resultat)->toBe([])
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});

// ---------------------------------------------------------------------------
// 8. Le passe-plat RapportService — un trou relevé par la revue de la tâche
//    précédente : un passe-plat non exercé peut perdre un argument sans
//    qu'aucune assertion ne bronche.
// ---------------------------------------------------------------------------

it('expose previsionsParOperationEtCompte via le passe-plat RapportService', function (): void {
    $compte = Compte::factory()->numero('611B')->create();
    [$operation] = ppocOperation();
    $tiers = Tiers::factory()->pourDepenses()->create();
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => PPOC_DATE_DANS]);

    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 500.00,
    ]);

    $resultat = app(RapportService::class)->previsionsParOperationEtCompte(2025, [$operation->id]);

    expect($resultat)->toBe([
        $operation->id => [
            $compte->id => 500.00,
        ],
    ]);
});
