<?php

declare(strict_types=1);

// ArbreSelecteurOperations::construire() est l'extraction de
// RapportCompteResultatOperations::buildOperationTree() — partagée depuis
// cette tâche avec le futur rapport « Budget par opérations ». Ce fichier
// teste directement la classe, pas le composant Livewire qui reste couvert
// par ses propres tests (CompteResultatExportTogglesTest, etc.) : l'extraction
// est un déplacement, elle ne change aucune assertion existante.

use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\TypeOperation;
use App\Services\Rapports\ArbreSelecteurOperations;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// 1. Groupement et tri — deux comptes, deux types sous le même compte, deux
//    opérations sous le même type. Chaque niveau est créé dans l'ordre
//    INVERSE de son ordre alphabétique, pour que le test tombe si un des
//    trois usort()/orderBy('nom') disparaissait (un tri absent laisserait
//    l'ordre de création, donc l'ordre inverse de celui attendu ici).
// ---------------------------------------------------------------------------

it('groupe par compte puis par type, et trie les trois niveaux par nom malgré un ordre de création inverse', function (): void {
    // Compte "Zoulou" créé EN PREMIER (id le plus bas) mais alphabétiquement
    // après "Alpha" : sans le usort() de fin de construire(), il ressortirait
    // en tête du résultat.
    $compteZoulou = Compte::factory()->create(['intitule' => 'Compte Zoulou']);

    // Sous Zoulou, type "Zebre" créé EN PREMIER, type "Anemone" EN SECOND :
    // ordre alphabétique inverse de l'ordre de création.
    $typeZebre = TypeOperation::factory()->create(['compte_id' => $compteZoulou->id, 'nom' => 'Type Zebre']);
    $opZeta = Operation::factory()->create(['type_operation_id' => $typeZebre->id, 'nom' => 'Operation Zeta']);
    $opAlphaSousZebre = Operation::factory()->create(['type_operation_id' => $typeZebre->id, 'nom' => 'Operation Alpha']);

    $typeAnemone = TypeOperation::factory()->create(['compte_id' => $compteZoulou->id, 'nom' => 'Type Anemone']);
    $opUnique = Operation::factory()->create(['type_operation_id' => $typeAnemone->id, 'nom' => 'Operation Unique']);

    // Compte "Alpha" créé EN SECOND (id plus haut) mais alphabétiquement
    // avant "Zoulou".
    $compteAlpha = Compte::factory()->create(['intitule' => 'Compte Alpha']);
    $typeSolo = TypeOperation::factory()->create(['compte_id' => $compteAlpha->id, 'nom' => 'Type Solo']);
    $opSolo = Operation::factory()->create(['type_operation_id' => $typeSolo->id, 'nom' => 'Operation Solo']);

    $eligibleIds = [$opZeta->id, $opAlphaSousZebre->id, $opUnique->id, $opSolo->id];

    $resultat = app(ArbreSelecteurOperations::class)->construire($eligibleIds);

    expect($resultat)->toBe([
        [
            'id' => $compteAlpha->id,
            'nom' => 'Compte Alpha',
            'types' => [
                [
                    'id' => $typeSolo->id,
                    'nom' => 'Type Solo',
                    'operations' => [
                        ['id' => $opSolo->id, 'nom' => 'Operation Solo'],
                    ],
                ],
            ],
        ],
        [
            'id' => $compteZoulou->id,
            'nom' => 'Compte Zoulou',
            'types' => [
                [
                    'id' => $typeAnemone->id,
                    'nom' => 'Type Anemone',
                    'operations' => [
                        ['id' => $opUnique->id, 'nom' => 'Operation Unique'],
                    ],
                ],
                [
                    'id' => $typeZebre->id,
                    'nom' => 'Type Zebre',
                    'operations' => [
                        ['id' => $opAlphaSousZebre->id, 'nom' => 'Operation Alpha'],
                        ['id' => $opZeta->id, 'nom' => 'Operation Zeta'],
                    ],
                ],
            ],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// 2. Liste vide — rend [] sans exécuter de requête (le early-return, pas
//    seulement une requête qui ne rendrait rien).
// ---------------------------------------------------------------------------

it('rend un tableau vide sans toucher la base quand la liste d\'éligibles est vide', function (): void {
    DB::enableQueryLog();

    $resultat = app(ArbreSelecteurOperations::class)->construire([]);

    expect($resultat)->toBe([])
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
});

// ---------------------------------------------------------------------------
// 3. Opération sans type d'opération. Le seul null-safe que ce montage exerce
//    vraiment est `$type?->compte` : sans lui, l'accès sur null lèverait au
//    lieu de retomber sur '—'/0. Les `??` qui suivent, eux, masquent déjà
//    l'accès sur null — retirer leur `?->` ne change rien (mutants
//    équivalents). Ce test épingle donc le repli complet 'Sans type'/'—'/0
//    d'une opération orpheline, pas chacune des flèches prise isolément.
// ---------------------------------------------------------------------------

it('replie sur le libellé "Sans type" et l\'id de groupement 0 quand l\'opération n\'a pas de type', function (): void {
    $operation = Operation::factory()->create([
        'type_operation_id' => null,
        'nom' => 'Operation orpheline',
    ]);

    $resultat = app(ArbreSelecteurOperations::class)->construire([$operation->id]);

    expect($resultat)->toBe([
        [
            'id' => 0,
            'nom' => '—',
            'types' => [
                [
                    'id' => 0,
                    'nom' => 'Sans type',
                    'operations' => [
                        ['id' => $operation->id, 'nom' => 'Operation orpheline'],
                    ],
                ],
            ],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// 4. Type d'opération sans compte. Le type existe — id et nom réels —, seul
//    son compte est absent. Ce que ce cas prouve, et qu'aucun autre ne couvre :
//    l'identité propre du type SURVIT au repli du niveau compte. Le test 3
//    produit lui aussi '—'/0 au niveau compte, mais en perdant le type au
//    passage ; les deux sorties seraient indiscernables sans ce montage.
// ---------------------------------------------------------------------------

it('replie sur le libellé "—" et l\'id de groupement 0 quand le type n\'a pas de compte', function (): void {
    $type = TypeOperation::factory()->create([
        'compte_id' => null,
        'nom' => 'Type sans compte',
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $type->id,
        'nom' => 'Operation rattachée',
    ]);

    $resultat = app(ArbreSelecteurOperations::class)->construire([$operation->id]);

    expect($resultat)->toBe([
        [
            'id' => 0,
            'nom' => '—',
            'types' => [
                [
                    'id' => $type->id,
                    'nom' => 'Type sans compte',
                    'operations' => [
                        ['id' => $operation->id, 'nom' => 'Operation rattachée'],
                    ],
                ],
            ],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// 5. Isolation tenant — Operation est un modèle tenant-scopé (TenantModel) :
//    c'est le scope global qui doit écarter l'opération de l'autre
//    association, construire() ne fait lui-même aucun filtre tenant explicite
//    (seul un whereIn('id', ...) sur Operation::query()). Une opération
//    légitime du tenant courant est incluse pour que le test exige un
//    résultat NON VIDE qui la contienne — si le scope disparaissait, l'id de
//    l'opération intruse remonterait aussi dans ce même résultat non vide, ce
//    qu'un simple `toBe([])` ne pourrait jamais détecter.
// ---------------------------------------------------------------------------

it('écarte un id éligible d\'une autre association via le scope tenant de Operation, sans vider le résultat légitime', function (): void {
    $autre = Association::factory()->create();

    $compte = Compte::factory()->create(['intitule' => 'Compte legit']);
    $type = TypeOperation::factory()->create(['compte_id' => $compte->id, 'nom' => 'Type legit']);
    $operation = Operation::factory()->create(['type_operation_id' => $type->id, 'nom' => 'Operation legit']);

    TenantContext::boot($autre);
    $compteAutre = Compte::factory()->create(['intitule' => 'Compte intrus']);
    $typeAutre = TypeOperation::factory()->create(['compte_id' => $compteAutre->id, 'nom' => 'Type intrus']);
    $operationAutre = Operation::factory()->create(['type_operation_id' => $typeAutre->id, 'nom' => 'Operation intruse']);
    TenantContext::boot($this->association);

    // L'id de l'opération intruse figure explicitement dans la liste demandée :
    // c'est le seul montage où un scope manquant se verrait (un whereIn qui
    // l'aurait déjà filtré ne prouverait rien).
    $resultat = app(ArbreSelecteurOperations::class)->construire([$operation->id, $operationAutre->id]);

    $idsRendus = collect($resultat)
        ->flatMap(fn (array $groupe): array => $groupe['types'])
        ->flatMap(fn (array $type): array => $type['operations'])
        ->pluck('id');

    expect($idsRendus)->toContain($operation->id)
        ->and($idsRendus)->not->toContain($operationAutre->id);
});
