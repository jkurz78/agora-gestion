<?php

declare(strict_types=1);

// CompteResultatBuilder::previsionsParOperationEtCompte() est la porte étroite
// ouverte pour le rapport « Budget par opérations » : elle délègue à
// fetchPrevisionsFlatEntries() (privée, déjà testée par
// CompteResultatBuilderPrevisionsPlatesTest.php) plutôt que de recopier ses
// jointures. Ce fichier teste donc la couche d'agrégation qui lui est propre :
// le filtrage de l'exercice 0 (séance sans date), le cumul + arrondi, et le
// passe-plat RapportService — pas le OR de date ni le scope tenant des deux
// sources, déjà couverts côté fetchPrevisionsFlatEntries().

use App\Models\Association;
use App\Models\Compte;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
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
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
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
// 6. Isolation tenant — un test qui tue le filtre précis : l'opération EST
//    dans la liste demandée (le whereIn ne l'écarterait pas), seule la ligne
//    en base appartient à une autre association. Retirer le scope tenant sur
//    ep.association_id ferait fuiter cette ligne ; un test où tout serait chez
//    l'autre association ne le prouverait pas (le whereIn suffirait déjà).
// ---------------------------------------------------------------------------

it('exclut une prévision d\'encadrement d\'une autre association même quand son operation_id figure dans la liste demandée', function (): void {
    $autre = Association::factory()->create();

    $compte = Compte::factory()->numero('611B')->create();
    [$operation] = ppocOperation();
    $tiers = Tiers::factory()->pourDepenses()->create();
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => PPOC_DATE_DANS]);

    // Insertion directe : contourne TenantModel pour forcer une incohérence
    // (association_id de l'autre tenant, operation_id de l'opération courante)
    // qu'aucune écriture applicative normale ne produit, mais que seul le
    // scope tenant explicite sur ep.association_id peut arrêter.
    DB::table('encadrement_previsions')->insert([
        'association_id' => $autre->id,
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 9999.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resultat = app(CompteResultatBuilder::class)->previsionsParOperationEtCompte(2025, [$operation->id]);

    expect($resultat)->toBe([]);
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
