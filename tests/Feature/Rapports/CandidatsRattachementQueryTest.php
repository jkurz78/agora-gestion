<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Extourne;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Services\ExerciceService;
use App\Services\Rapports\CandidatsRattachementQuery;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| CandidatsRattachementQuery : la détection des candidats au rattachement
|--------------------------------------------------------------------------
|
| La requête REPRODUIT en SQL les gardes de RattachementExerciceService::motifDeRefus(), qui reste l'autorité
| à l'écriture : les tests d'exclusion disent chacun ce qu'elle écarte, et les tests d'accord (en fin de
| fichier) vérifient, sur un jeu de lignes de toutes les formes, qu'elle ne propose rien que le service
| refuse, et ne laisse de côté rien que le service accepte (dont l'opération démarre après l'exercice).
|
| Les bornes (premier et dernier jour de l'exercice, de l'opération, de l'annulation) sont éprouvées sur la
| colonne telle qu'Eloquent l'écrit (« Y-m-d H:i:s » sous SQLite) ET telle qu'un insert brut l'écrit
| (« Y-m-d ») : un whereBetween sur la date ferait tomber celles du dernier jour sous SQLite.
|
| Exercice 2025 = 01/09/2025 → 31/08/2026 ; horloge figée au 15/01/2026.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 411/401 (contreparties) et 486/487 (régularisation, pour les rattachements réels).
    SystemeSeeder::seed();
});

// ── Helpers ──────────────────────────────────────────────────────────────────

/** @return array{eligibles: Collection<int, object>, ecartees: Collection<int, object>} */
function candidatsDetecter(int $exercice = 2025): array
{
    return app(CandidatsRattachementQuery::class)->pourExercice($exercice);
}

/**
 * Les identifiants des lignes proposées.
 *
 * @return list<int>
 */
function candidatsIdsEligibles(int $exercice = 2025): array
{
    return candidatsDetecter($exercice)['eligibles']->pluck('transaction_ligne_id')->map(fn ($id): int => (int) $id)->all();
}

/**
 * Les identifiants des lignes écartées.
 *
 * @return list<int>
 */
function candidatsIdsEcartees(int $exercice = 2025): array
{
    return candidatsDetecter($exercice)['ecartees']->pluck('transaction_ligne_id')->map(fn ($id): int => (int) $id)->all();
}

function candidatsEstProposee(TransactionLigne $ligne, int $exercice = 2025): bool
{
    return in_array((int) $ligne->id, candidatsIdsEligibles($exercice), true);
}

/**
 * Une opération de l'association courante. Par défaut celle du cas réel : elle démarre le 18/09/2026, dans N+1.
 * Sans type : la fabrique en crée un par opération, avec un compte « 706xx » au hasard, qui peut heurter le
 * numéro « 7xxxx » que CompteFactory tire pour une ligne du même test (unicité association + numéro).
 */
function candidatsOperation(string $debut = '2026-09-18', ?string $fin = '2027-06-18', string $nom = 'EquiThe FE sept 2026'): Operation
{
    return Operation::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        'type_operation_id' => null,
        'nom' => $nom,
        'date_debut' => $debut,
        'date_fin' => $fin,
    ]);
}

/** La ligne d'origine du dataset PCA / CCA, ventilée sur $operation (par défaut, une opération qui démarre le 18/09/2026). */
function candidatsLigne(TestCase $test, int $classe = 7, ?Operation $operation = null, ?float $montant = null): TransactionLigne
{
    return $test->ligneOrigine(
        $classe,
        $montant ?? ($classe === 7 ? 175.0 : 90.0),
        operationId: (int) ($operation ?? candidatsOperation())->id,
    );
}

/**
 * Une ligne sur le compte et dans le sens choisis, ventilée sur $operationId, sur une pièce du 08/04/2026.
 * Un compte nul donne une ligne sans compte (héritage d'avant la partie double).
 */
function candidatsLigneSurCompte(?Compte $compte, float $debit, float $credit, TypeTransaction $type, JournalComptable $journal, ?int $operationId): TransactionLigne
{
    $piece = Transaction::create([
        'association_id' => (int) TenantContext::currentId(),
        'type' => $type,
        'date' => '2026-04-08',
        'libelle' => 'Pièce d’origine',
        'montant_total' => max($debit, $credit),
        'saisi_par' => auth()->id(),
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => $journal,
    ]);

    return TransactionLigne::create([
        'transaction_id' => $piece->id,
        'compte_id' => $compte?->id,
        'debit' => $debit,
        'credit' => $credit,
        'montant' => max($debit, $credit),
        'operation_id' => $operationId,
        'libelle' => 'Ligne d’origine',
    ]);
}

/**
 * Redate la pièce de la ligne. 'eloquent' : par le modèle, comme tout le code (« Y-m-d H:i:s » sous SQLite, où la
 * colonne date est un texte) ; 'brut' : par un update brut (« Y-m-d »). Rend la valeur stockée.
 */
function candidatsDaterPiece(TransactionLigne $ligne, string $date, string $stockage): string
{
    if ($stockage === 'eloquent') {
        Transaction::withoutEvents(function () use ($ligne, $date): void {
            $piece = Transaction::findOrFail($ligne->transaction_id);
            $piece->date = $date;
            $piece->save();
        });
    } else {
        DB::table('transactions')->where('id', $ligne->transaction_id)->update(['date' => $date]);
    }

    return (string) DB::table('transactions')->where('id', $ligne->transaction_id)->value('date');
}

/** Redate le début de l'opération, comme candidatsDaterPiece(). Rend la valeur stockée. */
function candidatsDaterOperation(Operation $operation, string $debut, string $stockage): string
{
    if ($stockage === 'eloquent') {
        $operation->date_debut = $debut;
        $operation->save();
    } else {
        DB::table('operations')->where('id', $operation->id)->update(['date_debut' => $debut]);
    }

    return (string) DB::table('operations')->where('id', $operation->id)->value('date_debut');
}

/**
 * Garde anti-test-vide des bornes : sous SQLite et par Eloquent, la valeur stockée porte une heure, ce qui fait
 * tomber un whereBetween sur le dernier jour ; sinon la date est stockée telle quelle.
 */
function candidatsAssertStockage(string $stocke, string $date, string $stockage): void
{
    $attendu = DB::getDriverName() === 'sqlite' && $stockage === 'eloquent' ? $date.' 00:00:00' : $date;

    expect($stocke)->toBe($attendu);
}

/** Annule réellement la pièce par TransactionExtourneService, l'annulation étant datée de $dateAnnulation. */
function candidatsAnnuler(Transaction $piece, string $dateAnnulation): Extourne
{
    return app(TransactionExtourneService::class)->extourner(
        $piece,
        ExtournePayload::fromOrigine($piece, ['date' => $dateAnnulation]),
    );
}

/**
 * Une pièce réellement annulée, ligne 6/7 ventilée sur $operation.
 *
 * @return array{origine: TransactionLigne, miroir: TransactionLigne, extourne: Extourne}
 */
function candidatsPieceAnnulee(TestCase $test, int $classe, string $dateAnnulation, ?Operation $operation = null): array
{
    $origine = $test->ligneOrigineEquilibree(
        $classe,
        $classe === 7 ? 175.0 : 90.0,
        ModePaiement::Virement,
        operationId: (int) ($operation ?? candidatsOperation())->id,
    );
    $extourne = candidatsAnnuler(Transaction::findOrFail($origine->transaction_id), $dateAnnulation);

    $miroir = TransactionLigne::where('transaction_id', $extourne->transaction_extourne_id)
        ->where('compte_id', $origine->compte_id)
        ->sole();

    return ['origine' => $origine, 'miroir' => $miroir, 'extourne' => $extourne];
}

/**
 * Fixe la date de la pièce d'annulation. 'eloquent' : celle que le service a écrite (« Y-m-d H:i:s » sous SQLite) ;
 * 'brut' : réécrite « Y-m-d ». Rend la valeur stockée.
 */
function candidatsStockerDateDuMiroir(Extourne $extourne, string $date, string $stockage): string
{
    if ($stockage === 'brut') {
        DB::table('transactions')->where('id', $extourne->transaction_extourne_id)->update(['date' => $date]);
    }

    return (string) DB::table('transactions')->where('id', $extourne->transaction_extourne_id)->value('date');
}

/** Rattache la ligne par le vrai service, au nom de l'utilisateur connecté. */
function candidatsRattacher(TransactionLigne $ligne): Provision
{
    return app(RattachementExerciceService::class)->rattacher($ligne->fresh(), null, auth()->user());
}

/** Affecte la ligne sur deux opérations qui démarrent après l'exercice (la ventilation vit alors dans les affectations). */
function candidatsAffecter(TransactionLigne $ligne): void
{
    $moitie = round((float) $ligne->montant / 2, 2);

    foreach ([$moitie, round((float) $ligne->montant - $moitie, 2)] as $part) {
        TransactionLigneAffectation::create([
            'transaction_ligne_id' => $ligne->id,
            'operation_id' => candidatsOperation()->id,
            'montant' => $part,
        ]);
    }
}

/**
 * Insère, SANS passer par aucun scope ni aucun modèle, une ligne de produit de 175 € sur une pièce du 08/04/2026
 * et une opération qui démarre le 18/09/2026 : chacune des trois tables (la pièce, le compte, l'opération) est
 * rattachée à l'association donnée. Rend l'identifiant de la ligne.
 */
function candidatsInsererBrut(int $associationPiece, int $associationCompte, int $associationOperation): int
{
    $compteId = DB::table('comptes')->insertGetId([
        'association_id' => $associationCompte,
        'numero_pcg' => '79901',
        'intitule' => 'Produit inséré à la main',
        'classe' => 7,
    ]);

    $operationId = DB::table('operations')->insertGetId([
        'association_id' => $associationOperation,
        'nom' => 'Opération insérée à la main',
        'date_debut' => '2026-09-18',
        'date_fin' => '2027-06-18',
    ]);

    $pieceId = DB::table('transactions')->insertGetId([
        'association_id' => $associationPiece,
        'type' => 'recette',
        'date' => '2026-04-08',
        'libelle' => 'Pièce insérée à la main',
        'montant_total' => 175,
        'equilibree' => 1,
        'type_ecriture' => 'normale',
        'journal' => 'vente',
    ]);

    return (int) DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $pieceId,
        'compte_id' => $compteId,
        'operation_id' => $operationId,
        'debit' => 0,
        'credit' => 175,
        'montant' => 175,
        'libelle' => 'Ligne insérée à la main',
    ]);
}

// ── Ce qui est proposé ───────────────────────────────────────────────────────

describe('CandidatsRattachementQuery : ce qui est proposé', function () {
    test('AC-13 — une ligne de produit au crédit ou de charge au débit, ventilée sur une opération qui démarre après l’exercice, est proposée avec ce que l’écran affiche', function (int $classe, float $montant) {
        $ligne = candidatsLigne($this, $classe, candidatsOperation('2026-09-18', '2027-06-18', 'EquiThe FE sept 2026'), $montant);
        $ligne->load('transaction', 'compte');

        $resultat = candidatsDetecter();

        expect($resultat['eligibles'])->toHaveCount(1)
            ->and($resultat['ecartees'])->toHaveCount(0);

        $candidate = $resultat['eligibles']->sole();

        expect($candidate->transaction_ligne_id)->toBe((int) $ligne->id)
            ->and($candidate->transaction_id)->toBe((int) $ligne->transaction_id)
            ->and($candidate->piece_date->toDateString())->toBe('2026-04-08')
            ->and($candidate->piece_libelle)->toBe('Pièce d’origine')
            ->and($candidate->ligne_libelle)->toBe('Ligne d’origine')
            ->and($candidate->tiers_id)->toBeNull()
            ->and($candidate->tiers_nom)->toBeNull()
            ->and($candidate->numero_pcg)->toBe($ligne->compte->numero_pcg)
            ->and($candidate->compte_intitule)->toBe($ligne->compte->intitule)
            ->and($candidate->classe)->toBe($classe)
            ->and($candidate->operation_id)->toBe((int) $ligne->operation_id)
            ->and($candidate->operation_nom)->toBe('EquiThe FE sept 2026')
            ->and($candidate->operation_debut->toDateString())->toBe('2026-09-18')
            ->and($candidate->operation_fin->toDateString())->toBe('2027-06-18')
            ->and($candidate->montant)->toBe($montant)
            ->and($candidate->nb_affectations)->toBe(0)
            ->and($candidate->numero_piece)->toBe($ligne->transaction->numero_piece);
    })->with('rattachement_pca_cca');

    test('le montant est le plus grand du débit et du crédit, comme celui que le rattachement reprend (D12)', function () {
        // Une ligne de charge dont le montant stocké diffère : seul le débit fait foi.
        $ligne = candidatsLigne($this, 6, null, 90.0);
        TransactionLigne::whereKey($ligne->id)->update(['montant' => 12.5]);

        expect(candidatsDetecter()['eligibles']->sole()->montant)->toBe(90.0);
    });

    test('le tiers est celui de la pièce (D3) : son identifiant et son nom d’affichage', function () {
        $ligne = candidatsLigne($this, 7);
        $tiers = Tiers::factory()->create(['type' => 'particulier', 'prenom' => 'Aurore', 'nom' => 'Dupont', 'entreprise' => null]);
        Transaction::whereKey($ligne->transaction_id)->update(['tiers_id' => $tiers->id]);

        $candidate = candidatsDetecter()['eligibles']->sole();

        expect($candidate->tiers_id)->toBe((int) $tiers->id)
            ->and($candidate->tiers_nom)->toBe('Aurore DUPONT');
    });

    test('le tiers d’une autre association n’est ni nommé ni rendu : la ligne reste proposée', function () {
        $ligne = candidatsLigne($this, 7);
        $autre = Association::factory()->create();
        $tiersEtranger = DB::table('tiers')->insertGetId(['association_id' => $autre->id, 'type' => 'particulier', 'nom' => 'Etranger', 'prenom' => 'Zoé']);
        Transaction::whereKey($ligne->transaction_id)->update(['tiers_id' => $tiersEtranger]);

        $candidate = candidatsDetecter()['eligibles']->sole();

        expect($candidate->transaction_ligne_id)->toBe((int) $ligne->id)
            ->and($candidate->tiers_id)->toBeNull()
            ->and($candidate->tiers_nom)->toBeNull();
    });

    test('les candidates sont triées par date de pièce, puis par pièce et par ligne', function () {
        $operation = candidatsOperation();
        $tardive = candidatsLigne($this, 7, $operation);
        $precoce = candidatsLigne($this, 6, $operation);
        $memeJourPremiere = candidatsLigne($this, 7, $operation);
        $memeJourSeconde = candidatsLigne($this, 7, $operation);

        candidatsDaterPiece($tardive, '2026-06-30', 'eloquent');
        candidatsDaterPiece($precoce, '2025-10-02', 'eloquent');

        // Une seconde ligne de la première pièce du jour, créée APRÈS la ligne de la seconde pièce : les lignes d'une
        // même pièce restent adjacentes.
        $soeur = TransactionLigne::create([
            'transaction_id' => $memeJourPremiere->transaction_id,
            'compte_id' => $memeJourPremiere->compte_id,
            'debit' => 0,
            'credit' => 10,
            'montant' => 10,
            'operation_id' => $operation->id,
            'libelle' => 'Ligne sœur',
        ]);
        expect($soeur->id)->toBeGreaterThan($memeJourSeconde->id);

        expect(candidatsIdsEligibles())->toBe([
            (int) $precoce->id,
            (int) $memeJourPremiere->id,
            (int) $soeur->id,
            (int) $memeJourSeconde->id,
            (int) $tardive->id,
        ]);
    });

    test('une seule requête est émise pour toute la détection', function () {
        $operation = candidatsOperation();
        candidatsAffecter(candidatsLigne($this, 7, $operation));
        candidatsLigne($this, 6, $operation);

        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $resultat = candidatsDetecter();

        expect($resultat['eligibles'])->toHaveCount(1)
            ->and($resultat['ecartees'])->toHaveCount(1)
            ->and($requetes)->toHaveCount(1);

        // L'ordre est total : date de pièce, pièce, ligne. Le dernier critère ne se voit pas dans les données (les
        // moteurs rendent d'ordinaire les lignes d'une pièce dans l'ordre de leur identifiant) : on le lit dans la requête.
        expect(str_replace(['`', '"'], '', $requetes[0]))->toEndWith('order by t.date asc, t.id asc, tl.id asc');
    });

    test('hors contexte d’association, rien n’est proposé (fermé par défaut)', function () {
        candidatsLigne($this, 7);
        expect(candidatsIdsEligibles())->toHaveCount(1);

        TenantContext::clear();

        $resultat = candidatsDetecter();

        expect($resultat['eligibles'])->toHaveCount(0)
            ->and($resultat['ecartees'])->toHaveCount(0);
    });
});

// ── Ce qui n'est pas candidat : l'opération et l'exercice ────────────────────

describe('CandidatsRattachementQuery : l’opération et l’exercice', function () {
    test('une opération qui démarre et se termine DANS l’exercice n’est pas candidate', function () {
        candidatsLigne($this, 7, candidatsOperation('2026-03-01', '2026-06-30'));

        expect(candidatsDetecter()['eligibles'])->toHaveCount(0);
    });

    test('une opération qui démarre avant l’exercice et se termine après n’est pas candidate : seul son début compte', function () {
        candidatsLigne($this, 7, candidatsOperation('2025-03-01', '2027-06-30'));

        expect(candidatsDetecter()['eligibles'])->toHaveCount(0);
    });

    test('une ligne sans opération n’est pas candidate', function () {
        $this->ligneOrigine(7, 175.0);

        expect(candidatsDetecter()['eligibles'])->toHaveCount(0);
    });

    test('une opération sans date de début n’est pas candidate', function () {
        candidatsLigne($this, 7, candidatsOperation('2026-09-18', null));
        $sansDebut = candidatsLigne($this, 6, candidatsOperation('2026-09-18'));
        DB::table('operations')->where('id', $sansDebut->operation_id)->update(['date_debut' => null]);

        expect(candidatsIdsEligibles())->toHaveCount(1)
            ->and(candidatsIdsEligibles())->not->toContain((int) $sansDebut->id);
    });

    test('la pièce d’un autre exercice n’est pas candidate à celui-ci, mais l’est au sien', function () {
        $ligne = candidatsLigne($this, 7, candidatsOperation('2027-10-01', '2028-06-30'));
        candidatsDaterPiece($ligne, '2025-04-08', 'eloquent');

        // Exercice 2024 (01/09/2024 → 31/08/2025) : la pièce y est, l'opération démarre bien après.
        expect(candidatsEstProposee($ligne, 2024))->toBeTrue()
            ->and(candidatsEstProposee($ligne, 2025))->toBeFalse()
            ->and(candidatsEstProposee($ligne, 2026))->toBeFalse();
    });

    test('bornes de la pièce — premier et dernier jour de l’exercice sont dedans, la veille et le lendemain dehors, quel que soit le stockage', function (string $date, int $exerciceAttendu, string $stockage) {
        // L'opération démarre en 2027-10 : après les exercices 2024, 2025 et 2026. Seule la date de la pièce décide.
        $ligne = candidatsLigne($this, 7, candidatsOperation('2027-10-01', '2028-06-30'));
        candidatsAssertStockage(candidatsDaterPiece($ligne, $date, $stockage), $date, $stockage);

        foreach ([2024, 2025, 2026] as $exercice) {
            expect(candidatsEstProposee($ligne, $exercice))->toBe($exercice === $exerciceAttendu, "pièce du {$date}, exercice {$exercice}");
        }
    })->with([
        'premier jour de 2025' => ['2025-09-01', 2025],
        'dernier jour de 2025' => ['2026-08-31', 2025],
        'dernier jour de 2024' => ['2025-08-31', 2024],
        'premier jour de 2026' => ['2026-09-01', 2026],
    ])->with(['stockage Eloquent' => ['eloquent'], 'stockage brut' => ['brut']]);

    test('bornes de l’opération — démarrer le premier jour de l’exercice suivant est candidat, la veille non, quel que soit le stockage', function (string $debut, bool $attendu, string $stockage) {
        $operation = candidatsOperation();
        $ligne = candidatsLigne($this, 7, $operation);
        candidatsAssertStockage(candidatsDaterOperation($operation, $debut, $stockage), $debut, $stockage);

        expect(candidatsEstProposee($ligne))->toBe($attendu);
    })->with([
        'le lendemain du dernier jour' => ['2026-09-01', true],
        'le dernier jour de l’exercice' => ['2026-08-31', false],
        'l’avant-veille' => ['2026-08-30', false],
        'le surlendemain' => ['2026-09-02', true],
    ])->with(['stockage Eloquent' => ['eloquent'], 'stockage brut' => ['brut']]);
});

// ── Ce qui est exclu ─────────────────────────────────────────────────────────

describe('CandidatsRattachementQuery : exclusions', function () {
    test('D9 — une ligne de sens inverse (709A au débit, 609 au crédit) n’est pas proposée', function (string $numero, float $debit, float $credit) {
        $operation = candidatsOperation();
        $compte = Compte::factory()->numero($numero)->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
        $estProduit = (int) $compte->classe === 7;
        $inverse = candidatsLigneSurCompte(
            $compte,
            $debit,
            $credit,
            $estProduit ? TypeTransaction::Recette : TypeTransaction::Depense,
            $estProduit ? JournalComptable::Vente : JournalComptable::Achat,
            (int) $operation->id,
        );
        // Le contrôle : la ligne de même forme, dans le bon sens, est proposée.
        $droite = candidatsLigne($this, $estProduit ? 7 : 6, $operation);

        expect(candidatsIdsEligibles())->toBe([(int) $droite->id])
            ->and(candidatsIdsEcartees())->toBe([])
            ->and(candidatsEstProposee($inverse))->toBeFalse();
    })->with([
        '709A (produit) au débit' => ['709A', 50.0, 0.0],
        '609 (charge) au crédit' => ['609', 0.0, 50.0],
    ]);

    test('une ligne de classe 4 ou sans compte, ventilée sur une opération qui démarre après l’exercice, n’est pas proposée', function (?string $numero, float $debit, float $credit) {
        $compte = $numero === null ? null : Compte::where('numero_pcg', $numero)->firstOrFail();
        $ligne = candidatsLigneSurCompte($compte, $debit, $credit, TypeTransaction::Recette, JournalComptable::Vente, (int) candidatsOperation()->id);

        expect(candidatsEstProposee($ligne))->toBeFalse()
            ->and(candidatsIdsEcartees())->toBe([]);
    })->with([
        '411 au débit' => ['411', 175.0, 0.0],
        '411 au crédit' => ['411', 0.0, 175.0],
        '401 au crédit' => ['401', 0.0, 175.0],
        '401 au débit' => ['401', 175.0, 0.0],
        'sans compte' => [null, 0.0, 175.0],
    ]);

    test('D14 — une ligne du journal des opérations diverses n’est pas proposée', function (int $classe) {
        $ligne = candidatsLigne($this, $classe);
        $controle = candidatsLigne($this, $classe);
        Transaction::whereKey($ligne->transaction_id)->update(['journal' => JournalComptable::Od->value]);

        expect(candidatsIdsEligibles())->toBe([(int) $controle->id]);
    })->with('rattachement_classes');

    test('un journal absent (colonne nullable) ne vaut pas OD : la ligne reste proposée, comme le service l’accepte', function () {
        $ligne = candidatsLigne($this, 7);

        if (! collect(Schema::getColumns('transactions'))->firstWhere('name', 'journal')['nullable']) {
            // MariaDB : transactions.journal est NOT NULL, l'état n'existe pas. Le test ne vaut que là où il peut naître.
            expect(fn () => DB::table('transactions')->where('id', $ligne->transaction_id)->update(['journal' => null]))
                ->toThrow(QueryException::class);

            return;
        }

        DB::table('transactions')->where('id', $ligne->transaction_id)->update(['journal' => null]);

        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne->fresh()))->toBeNull()
            ->and(candidatsEstProposee($ligne))->toBeTrue();
    });

    test('D13 — une pièce dont type_ecriture vaut extourne (une annulation) n’est pas proposée', function (int $classe) {
        $ligne = candidatsLigne($this, $classe);
        $controle = candidatsLigne($this, $classe);
        Transaction::whereKey($ligne->transaction_id)->update(['type_ecriture' => 'extourne']);

        // La prémisse : les autres déclencheurs sont faux, ce type seul exclut.
        expect(Transaction::find($ligne->transaction_id)->extournee_at)->toBeNull()
            ->and(Extourne::where('transaction_extourne_id', $ligne->transaction_id)->exists())->toBeFalse()
            ->and(candidatsIdsEligibles())->toBe([(int) $controle->id]);
    })->with('rattachement_classes');

    test('D13 — une pièce référencée comme annulation dans la table des extournes n’est pas proposée', function (int $classe) {
        $operation = candidatsOperation();
        $annulation = candidatsLigne($this, $classe, $operation);
        $annulee = candidatsLigne($this, $classe, $operation);
        $controle = candidatsLigne($this, $classe, $operation);

        Extourne::create([
            'transaction_origine_id' => $annulee->transaction_id,
            'transaction_extourne_id' => $annulation->transaction_id,
            'created_by' => $this->user->id,
        ]);

        // Les autres déclencheurs sont faux pour $annulation : type normal, pas d'extournee_at.
        $piece = Transaction::find($annulation->transaction_id);
        expect($piece->type_ecriture)->toBe('normale')
            ->and($piece->extournee_at)->toBeNull()
            ->and(candidatsIdsEligibles())->toContain((int) $annulee->id, (int) $controle->id)
            ->and(candidatsIdsEligibles())->not->toContain((int) $annulation->id);
    })->with('rattachement_classes');

    test('D13 — la ligne de l’annulation réelle n’est pas proposée, ni dans N ni dans N+1', function (string $date) {
        ['miroir' => $miroir] = candidatsPieceAnnulee($this, 7, $date);

        expect($miroir->transaction->type_ecriture)->toBe('extourne')
            ->and(candidatsEstProposee($miroir))->toBeFalse();
    })->with(['annulation dans N' => ['2026-04-20'], 'annulation dans N+1' => ['2026-09-01']]);

    test('D13 — la pièce annulée au plus tard le dernier jour de N, même avant sa propre date, n’est pas proposée', function (int $classe, string $dateAnnulation, string $stockage) {
        ['origine' => $origine, 'extourne' => $extourne] = candidatsPieceAnnulee($this, $classe, $dateAnnulation);
        candidatsAssertStockage(candidatsStockerDateDuMiroir($extourne, $dateAnnulation, $stockage), $dateAnnulation, $stockage);

        // La prémisse : la pièce est bien marquée annulée, et son miroir existe à la date voulue.
        expect(Transaction::find($origine->transaction_id)->extournee_at)->not->toBeNull();

        expect(candidatsEstProposee($origine))->toBeFalse();
    })->with([
        'PCA annulée en cours d’exercice' => [7, '2026-04-20'],
        'PCA annulée avant l’exercice de la pièce' => [7, '2025-06-01'],
        'PCA annulée le dernier jour de N' => [7, '2026-08-31'],
        'CCA annulée le dernier jour de N' => [6, '2026-08-31'],
    ])->with(['stockage Eloquent' => ['eloquent'], 'stockage brut' => ['brut']]);

    test('D13 — la pièce annulée après N reste proposée (le premier jour de N+1 est déjà après N)', function (int $classe, string $dateAnnulation, string $stockage) {
        ['origine' => $origine, 'extourne' => $extourne] = candidatsPieceAnnulee($this, $classe, $dateAnnulation);
        candidatsAssertStockage(candidatsStockerDateDuMiroir($extourne, $dateAnnulation, $stockage), $dateAnnulation, $stockage);

        // La prémisse : extournee_at est posé (à l'horloge figée, dans N) : seule la date de l'annulation compte.
        expect(Transaction::find($origine->transaction_id)->extournee_at->toDateString())->toBe('2026-01-15')
            ->and(candidatsEstProposee($origine))->toBeTrue();
    })->with([
        'PCA annulée le premier jour de N+1' => [7, '2026-09-01'],
        'CCA annulée le premier jour de N+1' => [6, '2026-09-01'],
        'PCA annulée plus tard dans N+1' => [7, '2027-02-01'],
    ])->with(['stockage Eloquent' => ['eloquent'], 'stockage brut' => ['brut']]);

    test('D13 — une pièce annulée sans enregistrement d’annulation, donc sans date, n’est pas proposée', function () {
        $ligne = candidatsLigne($this, 7);
        Transaction::whereKey($ligne->transaction_id)->update(['extournee_at' => now()]);

        expect(Extourne::count())->toBe(0)
            ->and(candidatsEstProposee($ligne))->toBeFalse();
    });

    test('D13 — une pièce annulée dont la pièce d’annulation a été supprimée n’est pas proposée', function () {
        ['origine' => $origine, 'miroir' => $miroir] = candidatsPieceAnnulee($this, 7, '2026-09-01');
        // La prémisse : tant que le miroir existe, l'origine est proposée.
        expect(candidatsEstProposee($origine))->toBeTrue();

        $miroir->transaction->delete();

        expect(Extourne::count())->toBe(1)
            ->and(candidatsEstProposee($origine))->toBeFalse();
    });

    test('D13 — une annulation supprimée logiquement (l’enregistrement d’extourne) ne compte pas : la date est introuvable, l’origine n’est pas proposée', function () {
        ['origine' => $origine, 'extourne' => $extourne] = candidatsPieceAnnulee($this, 7, '2026-09-01');
        expect(candidatsEstProposee($origine))->toBeTrue();

        $extourne->delete();

        expect(Extourne::count())->toBe(0)
            ->and(candidatsEstProposee($origine))->toBeFalse();
    });

    test('un enregistrement d’extourne supprimé logiquement ne fait pas de sa pièce une annulation : elle reste proposée', function () {
        $operation = candidatsOperation();
        $pretendueAnnulation = candidatsLigne($this, 7, $operation);
        $autre = candidatsLigne($this, 7, $operation);

        $extourne = Extourne::create([
            'transaction_origine_id' => $autre->transaction_id,
            'transaction_extourne_id' => $pretendueAnnulation->transaction_id,
            'created_by' => $this->user->id,
        ]);
        expect(candidatsEstProposee($pretendueAnnulation))->toBeFalse();

        $extourne->delete();

        expect(candidatsEstProposee($pretendueAnnulation))->toBeTrue();
    });

    test('AC-11 — les écritures d’un vrai rattachement ne sont jamais candidates : ni la dotation, ni l’extourne, ni l’origine', function (int $classe, float $montant) {
        // L'opération démarre en 2027-10 : après N (2025) ET après N+1 (2026), où l'extourne, datée du 01/09/2026, a
        // bien le sens et l'opération d'une candidate. Seul provision_id l'écarte.
        $origine = candidatsLigne($this, $classe, candidatsOperation('2027-10-01', '2028-06-30'), $montant);
        expect(candidatsEstProposee($origine))->toBeTrue();

        $provision = candidatsRattacher($origine);
        $ecritures = Transaction::where('provision_id', $provision->id)->get()->keyBy('type_ecriture');
        $dotation = TransactionLigne::where('transaction_id', $ecritures['normale']->id)->where('compte_id', $origine->compte_id)->sole();
        $extourne = TransactionLigne::where('transaction_id', $ecritures['extourne']->id)->where('compte_id', $origine->compte_id)->sole();

        // La prémisse : l'extourne est dans le sens attendu, sur l'opération de l'origine, dans l'exercice 2026.
        expect((float) ($classe === 7 ? $extourne->credit : $extourne->debit))->toBe($montant)
            ->and((int) $extourne->operation_id)->toBe((int) $origine->operation_id)
            ->and($ecritures['extourne']->date->toDateString())->toBe('2026-09-01');

        foreach ([2024, 2025, 2026, 2027] as $exercice) {
            $proposees = candidatsIdsEligibles($exercice);
            expect($proposees)->not->toContain((int) $dotation->id)
                ->and($proposees)->not->toContain((int) $extourne->id)
                ->and($proposees)->not->toContain((int) $origine->id);
        }
    })->with('rattachement_pca_cca');

    test('AC-11 — une pièce qui porte un provision_id est une écriture de rattachement, même si ni son journal, ni son type, ni son sens ne l’excluent', function (int $classe) {
        $operation = candidatsOperation();
        $origine = candidatsLigne($this, $classe, $operation);
        $autre = candidatsLigne($this, $classe, $operation);
        $controle = candidatsLigne($this, $classe, $operation);

        // Le rattachement d'une AUTRE ligne ; la pièce d'$origine y est reliée par provision_id, et par rien d'autre.
        $provision = candidatsRattacher($autre);
        Transaction::whereKey($origine->transaction_id)->update(['provision_id' => $provision->id]);

        $piece = Transaction::findOrFail($origine->transaction_id);
        expect((int) $piece->provision_id)->toBe((int) $provision->id)
            ->and($piece->type_ecriture)->toBe('normale')
            ->and($piece->journal)->not->toBe(JournalComptable::Od)
            ->and($piece->extournee_at)->toBeNull()
            ->and(app(RattachementExerciceService::class)->motifDeRefus($origine->fresh()))->toContain('écriture de rattachement');

        expect(candidatsIdsEligibles())->toBe([(int) $controle->id]);
    })->with('rattachement_classes');

    test('AC-13 — une ligne déjà rattachée sort de la liste', function (int $classe, float $montant) {
        $origine = candidatsLigne($this, $classe, null, $montant);
        $autre = candidatsLigne($this, $classe, candidatsOperation('2026-10-01', '2027-06-30'));
        expect(candidatsIdsEligibles())->toContain((int) $origine->id, (int) $autre->id);

        candidatsRattacher($origine);

        expect(candidatsIdsEligibles())->toBe([(int) $autre->id])
            ->and(candidatsIdsEcartees())->toBe([]);
    })->with('rattachement_pca_cca');

    test('une ligne dont le rattachement est retiré redevient candidate', function () {
        $origine = candidatsLigne($this, 7);
        $provision = candidatsRattacher($origine);
        expect(candidatsEstProposee($origine))->toBeFalse();

        app(RattachementExerciceService::class)->retirer($provision);

        expect(candidatsEstProposee($origine))->toBeTrue();
    });

    test('ce qui est supprimé n’est pas proposé : la pièce, la ligne, le compte, l’opération', function (string $suppression) {
        $ligne = candidatsLigne($this, 7);
        $controle = candidatsLigne($this, 6);

        match ($suppression) {
            'pièce' => Transaction::findOrFail($ligne->transaction_id)->delete(),
            'ligne' => TransactionLigne::findOrFail($ligne->id)->delete(),
            'compte' => Compte::findOrFail($ligne->compte_id)->delete(),
            'opération' => Operation::findOrFail($ligne->operation_id)->delete(),
        };

        expect(candidatsIdsEligibles())->toBe([(int) $controle->id]);
    })->with(['pièce' => ['pièce'], 'ligne' => ['ligne'], 'compte' => ['compte'], 'opération' => ['opération']]);
});

// ── AC-6 : les lignes à affectations sont écartées, avec leur motif ──────────

describe('CandidatsRattachementQuery : les lignes ventilées sur des opérations (D4)', function () {
    test('AC-6 — une ligne porteuse d’affectations est écartée avec le motif partagé, jamais proposée ni omise', function (int $classe, float $montant) {
        $operation = candidatsOperation();
        $ventilee = candidatsLigne($this, $classe, $operation, $montant);
        $simple = candidatsLigne($this, $classe, $operation, $montant);
        candidatsAffecter($ventilee);

        $resultat = candidatsDetecter();

        expect($resultat['eligibles']->pluck('transaction_ligne_id')->all())->toBe([(int) $simple->id])
            ->and($resultat['ecartees'])->toHaveCount(1);

        $ecartee = $resultat['ecartees']->sole();

        expect($ecartee->transaction_ligne_id)->toBe((int) $ventilee->id)
            ->and($ecartee->motif)->toBe(RattachementExerciceService::MOTIF_AFFECTATIONS)
            ->and($ecartee->nb_affectations)->toBe(2)
            ->and($ecartee->montant)->toBe($montant)
            ->and($ecartee->operation_nom)->toBe('EquiThe FE sept 2026');
    })->with('rattachement_pca_cca');

    test('LIMITE CONNUE — une ligne dont la ventilation ne vit que dans ses affectations (operation_id nul) n’est atteinte ni comme candidate ni comme écartée', function () {
        // Le cas de la subvention du § 2.4 : tl.operation_id est nul, la ventilation est dans les affectations. La
        // requête joint l'opération sur tl.operation_id (§ 6.1) : cette ligne ne l'atteint pas. Le service, lui, la
        // refuse pour ses affectations : c'est la recherche libre qui la montre avec son motif. Étendre la détection
        // (atteindre aussi les opérations des affectations) fera tomber ce test, volontairement.
        $ligne = $this->ligneOrigine(7, 175.0);
        candidatsAffecter($ligne);

        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne->fresh()))->toBe(RattachementExerciceService::MOTIF_AFFECTATIONS)
            ->and(candidatsIdsEligibles())->toBe([])
            ->and(candidatsIdsEcartees())->toBe([]);
    });

    test('une ligne à affectations qui satisfait une autre exclusion n’est pas écartée « pour affectations » : elle est exclue, sans bruit', function () {
        // Sens inverse ET affectations : le service dit « sens inverse » d'abord ; la détection l'exclut aussi.
        $compte = Compte::factory()->numero('709A')->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
        $ligne = candidatsLigneSurCompte($compte, 50.0, 0.0, TypeTransaction::Recette, JournalComptable::Vente, (int) candidatsOperation()->id);
        candidatsAffecter($ligne);

        expect(candidatsIdsEligibles())->toBe([])
            ->and(candidatsIdsEcartees())->toBe([]);
    });
});

// ── AC-12 : un test PAR FILTRE tenant ────────────────────────────────────────

describe('CandidatsRattachementQuery : isolation des associations (AC-12)', function () {
    test('contrôle — une donnée dont tout est dans l’association courante est proposée', function () {
        $ligneId = candidatsInsererBrut(
            (int) TenantContext::currentId(),
            (int) TenantContext::currentId(),
            (int) TenantContext::currentId(),
        );

        expect(candidatsIdsEligibles())->toBe([$ligneId]);
    });

    test('la pièce d’une autre association n’est pas proposée, même si son compte et son opération sont dans la nôtre', function () {
        $autre = Association::factory()->create();
        $ligneId = candidatsInsererBrut((int) $autre->id, (int) TenantContext::currentId(), (int) TenantContext::currentId());

        expect(DB::table('transaction_lignes')->where('id', $ligneId)->exists())->toBeTrue()
            ->and(candidatsIdsEligibles())->toBe([])
            ->and(candidatsIdsEcartees())->toBe([]);
    });

    test('le compte d’une autre association n’est pas proposé, même si la pièce et l’opération sont dans la nôtre', function () {
        $autre = Association::factory()->create();
        $ligneId = candidatsInsererBrut((int) TenantContext::currentId(), (int) $autre->id, (int) TenantContext::currentId());

        expect(DB::table('transaction_lignes')->where('id', $ligneId)->exists())->toBeTrue()
            ->and(candidatsIdsEligibles())->toBe([])
            ->and(candidatsIdsEcartees())->toBe([]);
    });

    test('l’opération d’une autre association n’est pas proposée, même si la pièce et le compte sont dans la nôtre', function () {
        $autre = Association::factory()->create();
        $ligneId = candidatsInsererBrut((int) TenantContext::currentId(), (int) TenantContext::currentId(), (int) $autre->id);

        expect(DB::table('transaction_lignes')->where('id', $ligneId)->exists())->toBeTrue()
            ->and(candidatsIdsEligibles())->toBe([])
            ->and(candidatsIdsEcartees())->toBe([]);
    });

    test('une ligne à affectations d’une autre association n’est pas écartée chez nous', function () {
        $autre = Association::factory()->create();
        $ligneId = candidatsInsererBrut((int) $autre->id, (int) $autre->id, (int) $autre->id);
        DB::table('transaction_ligne_affectations')->insert(['transaction_ligne_id' => $ligneId, 'montant' => 175]);

        expect(candidatsIdsEligibles())->toBe([])
            ->and(candidatsIdsEcartees())->toBe([]);
    });

    test('un enregistrement d’extourne d’une autre association ne fait pas de notre pièce une annulation : le service ne le voit pas, la détection non plus', function () {
        $autre = Association::factory()->create();
        $operation = candidatsOperation();
        $annulation = candidatsLigne($this, 7, $operation);
        $annulee = candidatsLigne($this, 7, $operation);

        DB::table('extournes')->insert([
            'transaction_origine_id' => $annulee->transaction_id,
            'transaction_extourne_id' => $annulation->transaction_id,
            'association_id' => $autre->id,
            'created_by' => $this->user->id,
        ]);

        // L'accord avec le service : Extourne est sous scope tenant, la pièce n'y est pas une annulation.
        expect(app(RattachementExerciceService::class)->motifDeRefus($annulation->fresh()))->toBeNull()
            ->and(candidatsIdsEligibles())->toContain((int) $annulation->id);
    });

    test('un enregistrement d’annulation d’une autre association ne date pas notre pièce annulée : la date est introuvable, elle n’est pas proposée', function () {
        $autre = Association::factory()->create();
        ['origine' => $origine, 'extourne' => $extourne] = candidatsPieceAnnulee($this, 7, '2026-09-01');
        expect(candidatsEstProposee($origine))->toBeTrue();

        DB::table('extournes')->where('id', $extourne->id)->update(['association_id' => $autre->id]);

        expect(app(RattachementExerciceService::class)->motifDeRefus($origine->fresh()))->toContain('introuvable')
            ->and(candidatsEstProposee($origine))->toBeFalse();
    });

    test('une pièce d’annulation d’une autre association ne date pas notre pièce annulée : elle n’est pas proposée', function () {
        $autre = Association::factory()->create();
        ['origine' => $origine, 'extourne' => $extourne] = candidatsPieceAnnulee($this, 7, '2026-09-01');
        expect(candidatsEstProposee($origine))->toBeTrue();

        DB::table('transactions')->where('id', $extourne->transaction_extourne_id)->update(['association_id' => $autre->id]);

        expect(app(RattachementExerciceService::class)->motifDeRefus($origine->fresh()))->toContain('introuvable')
            ->and(candidatsEstProposee($origine))->toBeFalse();
    });
});

// ── Accord avec RattachementExerciceService::motifDeRefus(), dans les deux sens ──

/**
 * Un jeu de lignes de toutes les formes, dont la plupart sont ventilées sur une opération qui démarre après
 * l'exercice 2025 (2026-09-18) ; certaines pour l'exercice 2026 (pièces d'octobre 2026, opération de 2027-10).
 */
function candidatsJeuDeFormes(TestCase $test): void
{
    $future = candidatsOperation();
    $futureN1 = candidatsOperation('2027-10-01', '2028-06-30', 'Opération de 2027');
    $dansExercice = candidatsOperation('2026-03-01', '2026-06-30', 'Opération de l’exercice');

    // Éligibles de N (PCA, CCA), un jour limite de l'opération
    $test->ligneOrigine(7, 175.0, operationId: (int) $future->id);
    $test->ligneOrigine(6, 90.0, operationId: (int) $future->id);
    $test->ligneOrigine(7, 21.0, operationId: (int) candidatsOperation('2026-09-01')->id);
    // Pas candidates : opération dans l'exercice, sa veille, aucune opération
    $test->ligneOrigine(7, 22.0, operationId: (int) $dansExercice->id);
    $test->ligneOrigine(7, 23.0, operationId: (int) candidatsOperation('2026-08-31')->id);
    $test->ligneOrigine(7, 24.0);

    // Sens inverse
    $compte709 = Compte::factory()->numero('709A')->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
    $compte609 = Compte::factory()->numero('609')->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
    candidatsLigneSurCompte($compte709, 50.0, 0.0, TypeTransaction::Recette, JournalComptable::Vente, (int) $future->id);
    candidatsLigneSurCompte($compte609, 0.0, 50.0, TypeTransaction::Depense, JournalComptable::Achat, (int) $future->id);

    // Classe 4 et sans compte
    candidatsLigneSurCompte(Compte::where('numero_pcg', '411')->firstOrFail(), 175.0, 0.0, TypeTransaction::Recette, JournalComptable::Vente, (int) $future->id);
    candidatsLigneSurCompte(null, 0.0, 175.0, TypeTransaction::Recette, JournalComptable::Vente, (int) $future->id);

    // Journal OD
    $od = $test->ligneOrigine(7, 31.0, operationId: (int) $future->id);
    Transaction::whereKey($od->transaction_id)->update(['journal' => JournalComptable::Od->value]);

    // Journal absent (là où la colonne l'accepte)
    if (collect(Schema::getColumns('transactions'))->firstWhere('name', 'journal')['nullable']) {
        $sansJournal = $test->ligneOrigine(7, 32.0, operationId: (int) $future->id);
        DB::table('transactions')->where('id', $sansJournal->transaction_id)->update(['journal' => null]);
    }

    // D13 : type extourne, référencée comme annulation, annulations réelles (dans N, au dernier jour, après N)
    $extourne = $test->ligneOrigine(7, 33.0, operationId: (int) $future->id);
    Transaction::whereKey($extourne->transaction_id)->update(['type_ecriture' => 'extourne']);

    $reference = $test->ligneOrigine(6, 34.0, operationId: (int) $future->id);
    $referencee = $test->ligneOrigine(6, 35.0, operationId: (int) $future->id);
    Extourne::create([
        'transaction_origine_id' => $referencee->transaction_id,
        'transaction_extourne_id' => $reference->transaction_id,
        'created_by' => auth()->id(),
    ]);

    candidatsPieceAnnulee($test, 7, '2026-04-20');
    candidatsPieceAnnulee($test, 6, '2026-08-31');
    candidatsPieceAnnulee($test, 7, '2026-09-01');
    candidatsPieceAnnulee($test, 6, '2027-02-01');

    // Annulée sans enregistrement, annulée dont le miroir est supprimé, dont l'extourne est supprimée logiquement
    $sansEnregistrement = $test->ligneOrigine(7, 36.0, operationId: (int) $future->id);
    Transaction::whereKey($sansEnregistrement->transaction_id)->update(['extournee_at' => now()]);

    ['miroir' => $miroirSupprime] = candidatsPieceAnnulee($test, 7, '2026-09-01');
    $miroirSupprime->transaction->delete();

    ['extourne' => $extourneSupprimee] = candidatsPieceAnnulee($test, 6, '2026-09-01');
    $extourneSupprimee->delete();

    // Rattachements réels : l'origine, la dotation et l'extourne (pour N, et pour N+1 sur l'opération de 2027)
    $rattachement = candidatsRattacher($test->ligneOrigine(7, 41.0, operationId: (int) $future->id));
    candidatsRattacher($test->ligneOrigine(6, 42.0, operationId: (int) $future->id));
    candidatsRattacher($test->ligneOrigine(7, 43.0, operationId: (int) $futureN1->id));
    candidatsRattacher($test->ligneOrigine(6, 44.0, operationId: (int) $futureN1->id));

    // Une pièce reliée à un rattachement par provision_id seul
    $reliee = $test->ligneOrigine(7, 45.0, operationId: (int) $future->id);
    Transaction::whereKey($reliee->transaction_id)->update(['provision_id' => $rattachement->id]);

    // Lignes ventilées sur des opérations
    candidatsAffecter($test->ligneOrigine(7, 175.0, operationId: (int) $future->id));
    candidatsAffecter($test->ligneOrigine(6, 90.0, operationId: (int) $future->id));
    candidatsAffecter($test->ligneOrigine(7, 60.0));

    // Supprimées : la pièce, la ligne, le compte, l'opération
    $test->ligneOrigine(7, 51.0, operationId: (int) $future->id)->transaction->delete();
    $test->ligneOrigine(7, 52.0, operationId: (int) $future->id)->delete();
    Compte::findOrFail($test->ligneOrigine(7, 53.0, operationId: (int) $future->id)->compte_id)->delete();
    Operation::findOrFail($test->ligneOrigine(7, 54.0, operationId: (int) candidatsOperation()->id)->operation_id)->delete();

    // Une pièce d'un autre exercice
    candidatsDaterPiece($test->ligneOrigine(7, 55.0, operationId: (int) $future->id), '2025-04-08', 'eloquent');

    // L'exercice 2026 : une éligible, une écartée, une pièce du 31/08/2026 (dans 2025) et une du 01/09/2026 (dans 2026)
    candidatsDaterPiece($test->ligneOrigine(7, 61.0, operationId: (int) $futureN1->id), '2026-10-10', 'eloquent');
    candidatsDaterPiece($test->ligneOrigine(6, 62.0, operationId: (int) $futureN1->id), '2026-09-01', 'brut');
    candidatsDaterPiece($test->ligneOrigine(7, 63.0, operationId: (int) $futureN1->id), '2026-08-31', 'eloquent');
    $ventileeN1 = $test->ligneOrigine(7, 64.0, operationId: (int) $futureN1->id);
    candidatsDaterPiece($ventileeN1, '2026-11-20', 'eloquent');
    candidatsAffecter($ventileeN1);
}

/**
 * Pour chaque ligne de l'association, ce que le service en dit (motifDeRefus()) et ce que la détection en dit.
 * Rend, par identifiant de ligne : le motif du service, et si la ligne est dans l'exercice et sur une opération
 * qui démarre après lui (la condition propre à la détection).
 *
 * @return array{motifs: array<int, ?string>, candidates: list<int>, eligibles: list<int>, ecartees: array<int, ?string>}
 */
function candidatsAccord(TestCase $test, int $exercice): array
{
    candidatsJeuDeFormes($test);

    $service = app(RattachementExerciceService::class);
    $exerciceService = app(ExerciceService::class);
    $debutSuivant = $exerciceService->dateRange($exercice + 1)['start'];

    $motifs = [];
    $candidates = [];

    foreach (TransactionLigne::query()->get() as $ligne) {
        $motifs[(int) $ligne->id] = $service->motifDeRefus($ligne);

        $piece = $ligne->transaction;
        $operation = $ligne->operation;

        $dansExercice = $piece !== null && $exerciceService->anneeForDate($piece->date) === $exercice;
        $surOperationFuture = $operation !== null && $operation->date_debut !== null && $operation->date_debut->gte($debutSuivant);

        if ($dansExercice && $surOperationFuture) {
            $candidates[] = (int) $ligne->id;
        }
    }

    $resultat = candidatsDetecter($exercice);

    return [
        'motifs' => $motifs,
        'candidates' => $candidates,
        'eligibles' => $resultat['eligibles']->pluck('transaction_ligne_id')->map(fn ($id): int => (int) $id)->all(),
        'ecartees' => $resultat['ecartees']->pluck('motif', 'transaction_ligne_id')->all(),
    ];
}

describe('CandidatsRattachementQuery : accord avec motifDeRefus(), dans les deux sens', function () {
    test('toute ligne proposée est acceptée par le service (la détection ne propose rien que le service refuse)', function (int $exercice) {
        $accord = candidatsAccord($this, $exercice);

        // Garde anti-test-vide : le jeu contient des lignes proposées et des lignes que le service refuse.
        expect($accord['eligibles'])->not->toBeEmpty()
            ->and(array_filter($accord['motifs'], fn (?string $motif): bool => $motif !== null))->not->toBeEmpty();

        foreach ($accord['eligibles'] as $ligneId) {
            expect($accord['motifs'][$ligneId])->toBeNull("ligne {$ligneId} proposée, refusée par le service : ".($accord['motifs'][$ligneId] ?? ''));
        }
    })->with(['exercice 2025' => [2025], 'exercice 2026' => [2026]]);

    test('toute ligne que le service accepte, de l’exercice et sur une opération qui démarre après lui, est proposée (la détection n’oublie rien)', function (int $exercice) {
        $accord = candidatsAccord($this, $exercice);

        $attendues = array_values(array_filter($accord['candidates'], fn (int $id): bool => $accord['motifs'][$id] === null));

        // Garde anti-test-vide : il y en a, et la détection n'en propose pas d'autres.
        expect($attendues)->not->toBeEmpty();

        foreach ($attendues as $ligneId) {
            expect(in_array($ligneId, $accord['eligibles'], true))->toBeTrue("ligne {$ligneId} acceptée par le service, absente de la détection");
        }

        expect($accord['eligibles'])->toEqualCanonicalizing($attendues);
    })->with(['exercice 2025' => [2025], 'exercice 2026' => [2026]]);

    test('toute ligne écartée l’est pour le motif que le service donne, et toute ligne que le service refuse pour ses affectations est écartée', function (int $exercice) {
        $accord = candidatsAccord($this, $exercice);

        // Garde anti-test-vide : il y a des écartées.
        expect($accord['ecartees'])->not->toBeEmpty();

        foreach ($accord['ecartees'] as $ligneId => $motif) {
            expect($motif)->toBe($accord['motifs'][$ligneId], "ligne {$ligneId} écartée");
        }

        $attendues = array_values(array_filter(
            $accord['candidates'],
            fn (int $id): bool => $accord['motifs'][$id] === RattachementExerciceService::MOTIF_AFFECTATIONS,
        ));

        expect(array_keys($accord['ecartees']))->toEqualCanonicalizing($attendues);
    })->with(['exercice 2025' => [2025], 'exercice 2026' => [2026]]);
});
