<?php

declare(strict_types=1);

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
use App\Tenant\TenantContext;

/**
 * ⚠️ Horloge de test gelée globalement (tests/Pest.php, Carbon::setTestNow) sur
 * 2026-01-15 10:00:00 : l'exercice affiché par défaut est donc 2025, borné du
 * 2025-09-01 au 2026-08-31 (ExerciceService::dateRange, exercice_mois_debut=9
 * par défaut sur AssociationFactory). Toutes les dates « dans l'exercice » de
 * ce fichier tombent franchement à l'intérieur de cette fenêtre — jamais sur
 * une borne (SQLite exclut le dernier jour d'un whereBetween date-only, cf.
 * feedback_sqlite_date_boundary) — et les dates « hors exercice » tombent
 * franchement dans l'exercice précédent (2024).
 *
 * fetchPrevisionsFlatEntries()/buildPrevisions() sont privées et pas encore
 * reliées à une API publique acceptant des bornes nulles (portée « tous les
 * exercices » : ce câblage est un lot ultérieur du chantier multi-exercices).
 * On les invoque donc par réflexion, comme le fait déjà
 * CompteResultatBuilderGrainDateTest.php pour fetchOperationRowsPD().
 */
const PPLAT_START = '2025-09-01';

const PPLAT_END = '2026-08-31';

const PPLAT_DATE_DANS = '2025-11-10'; // exercice 2025 (affiché)

const PPLAT_DATE_HORS = '2024-10-15'; // exercice 2024 (précédent)

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->scDep = Compte::factory()->depense()->numero('606')->create(['intitule' => 'Encadrement']);
    $this->scRec = Compte::factory()->numero('706')->create(['intitule' => 'Cotisations']);

    $this->typeOp = TypeOperation::factory()->create(['compte_id' => $this->scRec->id]);
    $this->operation = Operation::factory()->create(['type_operation_id' => $this->typeOp->id]);

    $this->tiersDep = Tiers::factory()->pourDepenses()->create();
    $this->tiersRec = Tiers::factory()->create();
    $this->participant = Participant::factory()->create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersRec->id,
    ]);
});

/**
 * Invoque CompteResultatBuilder::fetchPrevisionsFlatEntries() par réflexion.
 *
 * @param  array<int>  $operationIds
 * @return array<string, array>
 */
function pplatFetch(
    string $type,
    array $operationIds,
    bool $withSeance = false,
    bool $withTiers = false,
    bool $withOperation = false,
    ?string $start = PPLAT_START,
    ?string $end = PPLAT_END,
): array {
    $method = new ReflectionMethod(CompteResultatBuilder::class, 'fetchPrevisionsFlatEntries');
    $method->setAccessible(true);

    return $method->invoke(
        app(CompteResultatBuilder::class),
        $type, $operationIds, $withSeance, $withTiers, $withOperation, $start, $end,
    );
}

/**
 * Invoque CompteResultatBuilder::buildPrevisions() par réflexion.
 *
 * @param  array<int>  $operationIds
 * @param  list<int>  $allSeances
 * @return list<array<string, mixed>>
 */
function pplatBuild(
    string $type,
    array $operationIds,
    bool $parSeances = false,
    bool $parTiers = false,
    bool $parOperations = false,
    array $allSeances = [],
    ?string $start = PPLAT_START,
    ?string $end = PPLAT_END,
): array {
    $method = new ReflectionMethod(CompteResultatBuilder::class, 'buildPrevisions');
    $method->setAccessible(true);

    return $method->invoke(
        app(CompteResultatBuilder::class),
        $type, $operationIds, $parSeances, $parTiers, $parOperations, $allSeances, $start, $end,
    );
}

// ---------------------------------------------------------------------------
// 1. Séance dans l'exercice affiché → retenue en portée courante.
// ---------------------------------------------------------------------------

it('retient une prévision de charge dont la séance est dans l\'exercice affiché, en portée courante', function (): void {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => PPLAT_DATE_DANS]);
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersDep->id,
        'compte_id' => $this->scDep->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 200,
    ]);

    $map = pplatFetch('depense', [$this->operation->id]);

    expect($map)->toHaveCount(1);
    $entry = array_values($map)[0];
    expect((float) $entry['montant'])->toBe(200.0)
        ->and($entry['exercice'])->toBe(2025);
});

// ---------------------------------------------------------------------------
// 2. Séance hors exercice → écartée en portée courante, retenue bornes nulles.
// ---------------------------------------------------------------------------

it('écarte une prévision de charge hors exercice en portée courante, et la retient quand les bornes sont nulles', function (): void {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => PPLAT_DATE_HORS]);
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersDep->id,
        'compte_id' => $this->scDep->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 150,
    ]);

    $mapCourante = pplatFetch('depense', [$this->operation->id]);
    expect($mapCourante)->toBe([]);

    $mapTous = pplatFetch('depense', [$this->operation->id], start: null, end: null);
    expect($mapTous)->toHaveCount(1);
    $entry = array_values($mapTous)[0];
    expect((float) $entry['montant'])->toBe(150.0)
        ->and($entry['exercice'])->toBe(2024);
});

// ---------------------------------------------------------------------------
// 3. Séance sans date → groupe "Exercice non déterminé" (0), dans les deux portées.
// ---------------------------------------------------------------------------

it('retient une prévision dont la séance n\'a pas de date, dans les deux portées, sous exercice=0', function (): void {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => null]);
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersDep->id,
        'compte_id' => $this->scDep->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 90,
    ]);

    $mapCourante = pplatFetch('depense', [$this->operation->id]);
    expect($mapCourante)->toHaveCount(1);
    $entryCourante = array_values($mapCourante)[0];
    expect($entryCourante['exercice'])->toBe(0)
        ->and((float) $entryCourante['montant'])->toBe(90.0);

    $mapTous = pplatFetch('depense', [$this->operation->id], start: null, end: null);
    expect($mapTous)->toHaveCount(1);
    expect(array_values($mapTous)[0]['exercice'])->toBe(0);
});

// ---------------------------------------------------------------------------
// 4. Le piège du OR : une prévision d'une autre association ne fuit jamais,
//    y compris en portée courante où le filtre de date introduit le OR.
// ---------------------------------------------------------------------------

it('n\'expose jamais une prévision d\'une autre association, y compris quand le filtre de date introduit le OR', function (): void {
    // Prévision légitime : association courante, opération sélectionnée,
    // séance dans l'exercice affiché.
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => PPLAT_DATE_DANS]);
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersDep->id,
        'compte_id' => $this->scDep->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 100,
    ]);

    // Prévision piégée : AUTRE association, AUTRE opération (absente de
    // $operationIds), mais séance elle aussi datée dans la fenêtre affichée.
    // Si le OR n'est pas parenthésé dans sa propre closure, il se détache de
    // TOUTE la conjonction qui le précède (opération sélectionnée ET tenant)
    // — cette ligne remonterait alors seule, via `s.date BETWEEN ...`, sans
    // aucune autre condition pour la retenir.
    $autre = Association::factory()->create();
    TenantContext::boot($autre);
    $scAutre = Compte::factory()->depense()->numero('606')->create();
    $opAutre = Operation::factory()->create();
    $seanceAutre = Seance::create(['operation_id' => $opAutre->id, 'numero' => 1, 'date' => PPLAT_DATE_DANS]);
    EncadrementPrevision::create([
        'operation_id' => $opAutre->id,
        'tiers_id' => Tiers::factory()->create()->id,
        'compte_id' => $scAutre->id,
        'seance_id' => $seanceAutre->id,
        'montant_prevu' => 9999,
    ]);

    TenantContext::boot($this->association);

    $map = pplatFetch('depense', [$this->operation->id]);

    $total = collect($map)->sum('montant');
    expect($total)->toBe(100.0);
});

// ---------------------------------------------------------------------------
// 5. Une prévision de produit (reglements.montant_prevu) suit les mêmes règles.
// ---------------------------------------------------------------------------

it('applique les mêmes règles de date à une prévision de produit (reglements.montant_prevu)', function (): void {
    $seanceDans = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => PPLAT_DATE_DANS]);
    Reglement::create([
        'participant_id' => $this->participant->id,
        'seance_id' => $seanceDans->id,
        'montant_prevu' => 80,
    ]);

    $mapCourante = pplatFetch('recette', [$this->operation->id]);
    expect($mapCourante)->toHaveCount(1);
    expect((float) array_values($mapCourante)[0]['montant'])->toBe(80.0);

    // Deuxième participant/séance, hors exercice, même opération.
    $participant2 = Participant::factory()->create([
        'operation_id' => $this->operation->id,
        'tiers_id' => Tiers::factory()->create()->id,
    ]);
    $seanceHors = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2, 'date' => PPLAT_DATE_HORS]);
    Reglement::create([
        'participant_id' => $participant2->id,
        'seance_id' => $seanceHors->id,
        'montant_prevu' => 55,
    ]);

    $mapCouranteAvecHors = pplatFetch('recette', [$this->operation->id]);
    expect(collect($mapCouranteAvecHors)->sum('montant'))->toBe(80.0);

    $mapTous = pplatFetch('recette', [$this->operation->id], start: null, end: null);
    expect(collect($mapTous)->sum('montant'))->toBe(135.0);
});

// ---------------------------------------------------------------------------
// 6. La hiérarchie produite porte les mêmes clés qu'avant (hiérarchiserPrevisions).
// ---------------------------------------------------------------------------

it('produit une hiérarchie avec les mêmes clés qu\'avant : famille_id, famille_nom, comptes[].compte_id, .montant', function (): void {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => PPLAT_DATE_DANS]);
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersDep->id,
        'compte_id' => $this->scDep->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 250,
    ]);

    $hierarchy = pplatBuild('depense', [$this->operation->id]);

    expect($hierarchy)->toHaveCount(1);
    $cat = $hierarchy[0];
    expect($cat)->toHaveKeys(['famille_id', 'famille_nom', 'montant', 'comptes']);
    expect($cat['comptes'])->toHaveCount(1);

    $sc = $cat['comptes'][0];
    expect($sc)->toHaveKeys(['compte_id', 'compte_nom', 'montant']);
    expect((int) $sc['compte_id'])->toBe((int) $this->scDep->id);
    expect((float) $sc['montant'])->toBe(250.0);
});

it('retourne un tableau vide sans aucune prévision, comme hiérarchiserPrevisions avant elle', function (): void {
    $hierarchy = pplatBuild('depense', [$this->operation->id]);

    expect($hierarchy)->toBe([]);
});

// ---------------------------------------------------------------------------
// 7. Le libellé d'un tiers s'aligne sur celui du réalisé : formatTiersLabel()
//    et son repli "(sans tiers)", plus jamais le "—" de l'ancien
//    hiérarchiserPrevisions/Tiers::sqlRaisonSociale().
// ---------------------------------------------------------------------------

/**
 * Cas réel et atteignable : un tiers attaché (FK non nulle respectée sur
 * encadrement_previsions.tiers_id) mais sans nom ni prénom ni raison sociale.
 * Sous l'ancien hiérarchiserPrevisions, Tiers::sqlRaisonSociale() calcule une
 * chaîne vide → repli "—". Sous buildUnifiedHierarchy() (désormais partagée
 * avec le réalisé), formatTiersLabel() calcule la même chaîne vide mais ne
 * retombe JAMAIS sur "—" (son unique repli textuel est "(sans tiers)",
 * réservé à tiers_id=0 — hors de portée ici car la FK interdit un tiers_id
 * nul sur une prévision réelle). Preuve, avec de vraies données, que le repli
 * "—" a disparu du prévisionnel.
 */
it('un tiers réel sans nom ne produit plus le repli "—" de l\'ancien hiérarchiserPrevisions', function (): void {
    $tiersSansNom = Tiers::factory()->pourDepenses()->create([
        'type' => 'particulier',
        'nom' => null,
        'prenom' => null,
        'entreprise' => null,
    ]);
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => PPLAT_DATE_DANS]);
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $tiersSansNom->id,
        'compte_id' => $this->scDep->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 60,
    ]);

    $hierarchy = pplatBuild('depense', [$this->operation->id], parTiers: true);

    $label = $hierarchy[0]['comptes'][0]['tiers'][0]['label'];
    expect($label)->not->toBe('—');
});
