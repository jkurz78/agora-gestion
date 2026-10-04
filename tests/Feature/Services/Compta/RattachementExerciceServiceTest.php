<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\CasProvision;
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Extourne;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Services\TransactionExtourneService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| RattachementExerciceService : éligibilité, création, verrous
|--------------------------------------------------------------------------
|
| Les écritures que le service déclenche (comptes, dates, sens, dimension analytique) sont
| éprouvées dans RattachementEcrituresTest. Les helpers et le dataset communs aux deux
| fichiers sont dans tests/Helpers/Rattachement.php et tests/Datasets/Rattachement.php.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486 et 487 : les comptes de régularisation, posés par le seeder système.
    SystemeSeeder::seed();
});

// ── RattachementExerciceService : éligibilité et création ────────────────────
//
// motifDeRefus() est la source de vérité unique de l'éligibilité : le service s'y
// réfère, et l'écran et la requête de détection s'y référeront. Chaque garde a donc
// son test, et chaque refus est vérifié trois fois — le motif, le refus de
// rattacher(), et le fait que RIEN n'a été écrit (ni rattachement, ni écriture).

/**
 * Une ligne que ligneOrigine() ne fabrique pas : sur le compte et dans le sens
 * choisis, sur une pièce du 2026-04-08. Un compte nul donne une ligne sans compte
 * (héritage d'avant la partie double).
 */
function serviceRattachementLigne(?Compte $compte, float $debit, float $credit, TypeTransaction $type, JournalComptable $journal): TransactionLigne
{
    $transaction = Transaction::create([
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
        'transaction_id' => $transaction->id,
        'compte_id' => $compte?->id,
        'debit' => $debit,
        'credit' => $credit,
        'montant' => max($debit, $credit),
        'libelle' => 'Ligne d’origine',
    ]);
}

/**
 * Le refus complet d'une ligne : le motif la décrit, rattacher() lève une
 * RuntimeException portant ce motif, et la base n'a pas bougé d'une ligne.
 *
 * La ligne est relue : motifDeRefus() lit des relations, qu'une instance déjà
 * chargée ferait paraître à jour alors que la base a changé.
 */
function serviceRattachementAssertRefusee(TransactionLigne $ligne, string $fragmentDuMotif): void
{
    $service = app(RattachementExerciceService::class);
    $ligne = $ligne->fresh();

    expect($service->motifDeRefus($ligne))->toContain($fragmentDuMotif);

    $provisionsAvant = DB::table('provisions')->count();
    $transactionsAvant = DB::table('transactions')->count();

    expect(fn () => $service->rattacher($ligne, null, auth()->user()))
        ->toThrow(RuntimeException::class, $fragmentDuMotif);

    expect(DB::table('provisions')->count())->toBe($provisionsAvant);
    expect(DB::table('transactions')->count())->toBe($transactionsAvant);
}

/**
 * Une pièce équilibrée, prête à être annulée : une ligne de produit au crédit et un 411 au
 * débit (classe 7), ou une ligne de charge au débit et un 401 au crédit (classe 6). Datée
 * du 2026-04-08, avec un mode de paiement (l'annulation en exige un).
 *
 * @return array{origine: TransactionLigne, piece: Transaction} la ligne 6/7 et sa pièce
 */
function serviceRattachementPieceAAnnuler(int $classe): array
{
    $assocId = (int) TenantContext::currentId();
    $estProduit = $classe === 7;
    $montant = $estProduit ? 175.0 : 90.0;
    $compteResultat = ($estProduit ? Compte::factory()->recette() : Compte::factory()->depense())
        ->create(['association_id' => $assocId, 'actif' => true]);
    $compteTiers = Compte::where('numero_pcg', $estProduit ? '411' : '401')->firstOrFail();

    $piece = Transaction::create([
        'association_id' => $assocId,
        'type' => $estProduit ? TypeTransaction::Recette : TypeTransaction::Depense,
        'date' => '2026-04-08',
        'libelle' => 'Pièce à annuler',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Virement,
        'saisi_par' => auth()->id(),
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => $estProduit ? JournalComptable::Vente : JournalComptable::Achat,
    ]);
    $ligneResultat = TransactionLigne::create([
        'transaction_id' => $piece->id,
        'compte_id' => $compteResultat->id,
        'debit' => $estProduit ? 0 : $montant,
        'credit' => $estProduit ? $montant : 0,
        'montant' => $montant,
        'libelle' => 'Ligne d’origine',
    ]);
    TransactionLigne::create([
        'transaction_id' => $piece->id,
        'compte_id' => $compteTiers->id,
        'debit' => $estProduit ? $montant : 0,
        'credit' => $estProduit ? 0 : $montant,
        'montant' => $montant,
        'libelle' => 'Contrepartie',
    ]);

    return ['origine' => $ligneResultat, 'piece' => $piece];
}

/**
 * Annule réellement la pièce par TransactionExtourneService, l'annulation étant datée de
 * $dateAnnulation : la date comptable de la pièce miroir. extournee_at, lui, vaut now(),
 * soit le 2026-01-15 figé.
 */
function serviceRattachementAnnuler(Transaction $piece, string $dateAnnulation): Extourne
{
    return app(TransactionExtourneService::class)->extourner(
        $piece,
        ExtournePayload::fromOrigine($piece, ['date' => $dateAnnulation]),
    );
}

/**
 * Une pièce réellement annulée (voir serviceRattachementPieceAAnnuler() et
 * serviceRattachementAnnuler()).
 *
 * @return array{origine: TransactionLigne, miroir: TransactionLigne, extourne: Extourne} la ligne
 *                                                                                        6/7 de la pièce annulée, celle de l'annulation, et l'enregistrement
 */
function serviceRattachementPieceAnnulee(int $classe, string $dateAnnulation): array
{
    ['origine' => $origine, 'piece' => $piece] = serviceRattachementPieceAAnnuler($classe);
    $extourne = serviceRattachementAnnuler($piece, $dateAnnulation);

    $miroir = TransactionLigne::where('transaction_id', $extourne->transaction_extourne_id)
        ->where('compte_id', $origine->compte_id)
        ->sole();

    return ['origine' => $origine, 'miroir' => $miroir, 'extourne' => $extourne];
}

/**
 * Exécute $changement au tout début de la transaction du service, une seule fois, puis
 * rattache la ligne. Rend ce que rattacher() a rendu, ou lance ce qu'il a lancé.
 */
function serviceRattachementAvecChangementConcurrent(TransactionLigne $ligne, Closure $changement, User $auteur): Provision
{
    $niveauAvant = DB::transactionLevel();
    $fait = false;

    DB::listen(function ($requete) use (&$fait, $niveauAvant, $changement): void {
        if ($fait || DB::transactionLevel() <= $niveauAvant || ! str_contains($requete->sql, 'association')) {
            return;
        }

        $fait = true;
        $changement();
    });

    return app(RattachementExerciceService::class)->rattacher($ligne, null, $auteur);
}

// ── Helpers du retrait ───────────────────────────────────────────────────────

/** Rattache la ligne au nom de l'utilisateur connecté du test. */
function serviceRattachementRattachee(TransactionLigne $ligne): Provision
{
    return app(RattachementExerciceService::class)->rattacher($ligne, null, auth()->user());
}

/**
 * Les identifiants des écritures d'un rattachement, comptés sans aucun filtre (ni suppression
 * logique ni association). À relever AVANT un retrait : la clé étrangère
 * `transactions.provision_id` étant en `nullOnDelete`, une écriture qui survivrait à la
 * suppression du rattachement perdrait son `provision_id`, et plus aucune requête par
 * `provision_id` ne la retrouverait.
 *
 * @return list<int>
 */
function serviceRattachementEcritureIds(Provision $provision): array
{
    return DB::table('transactions')
        ->where('provision_id', (int) $provision->id)
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
}

/**
 * Ce qu'un rattachement possède en base : sa ligne de provisions, ses écritures et leurs
 * lignes, comptés par identifiant et sans aucun filtre. Un rattachement posé vaut
 * 1 / 2 / 4 ; un retrait réussi le ramène à 0 / 0 / 0 ; un retrait refusé le laisse tel quel.
 *
 * @param  list<int>  $ecritureIds  relevés par serviceRattachementEcritureIds() avant le retrait
 * @return array{provision: int, ecritures: int, lignes: int}
 */
function serviceRattachementEtat(Provision $provision, array $ecritureIds): array
{
    return [
        'provision' => DB::table('provisions')->where('id', (int) $provision->id)->count(),
        'ecritures' => DB::table('transactions')->whereIn('id', $ecritureIds)->count(),
        'lignes' => DB::table('transaction_lignes')->whereIn('transaction_id', $ecritureIds)->count(),
    ];
}

describe('RattachementExerciceService : éligibilité (motifDeRefus)', function () {
    test('une ligne de produit au crédit ou de charge au débit, ventilée sur une opération future, est rattachable', function (int $classe, float $montant) {
        $operation = Operation::factory()->create([
            'association_id' => TenantContext::currentId(),
            'date_debut' => '2026-09-18',
            'date_fin' => '2027-06-18',
        ]);
        $ligne = $this->ligneOrigine($classe, $montant, operationId: (int) $operation->id);

        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne))->toBeNull();
    })->with('rattachement_pca_cca');

    test('motifDeRefus() est une lecture simple : aucune de ses requêtes ne verrouille', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $requetes = [];

        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne))->toBeNull();

        // Garde anti-test-vide : le motif a bien lu les rattachements et les affectations.
        expect(array_filter($requetes, fn (string $sql) => str_contains($sql, 'provisions')))->not->toBeEmpty();
        expect(array_filter($requetes, fn (string $sql) => str_contains($sql, 'transaction_ligne_affectations')))->not->toBeEmpty();

        // C'est rattacher() qui verrouille, dans sa transaction ; l'écran et la détection
        // appellent motifDeRefus() hors de toute écriture. (Sous SQLite « for update » n'existe pas.)
        foreach ($requetes as $sql) {
            expect($sql)->not->toContain('for update');
        }
    });

    test('une ligne dont la pièce a été supprimée est refusée : la pièce n’existe plus', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $ligne->transaction->delete();

        serviceRattachementAssertRefusee($ligne, 'La pièce de cette ligne a été supprimée.');
    });

    test('AC-11 — après un rattachement, la ligne 6/7 de la dotation et celle de l’extourne sont refusées comme écritures de rattachement', function (int $classe, float $montant) {
        $origine = $this->ligneOrigine($classe, $montant);
        $provision = app(RattachementExerciceService::class)->rattacher($origine, null, $this->user);

        $ecritures = Transaction::where('provision_id', $provision->id)->get()->keyBy('type_ecriture');
        expect($ecritures->keys()->sort()->values()->all())->toBe(['extourne', 'normale']);

        // La dotation d'une PCA est au débit d'un compte de classe 7, celle d'une CCA
        // au crédit d'un compte de classe 6 : sens inverse. Leur extourne, elle, est
        // dans le sens attendu. Seul le motif « écriture de rattachement » les distingue
        // de ce qu'une autre garde (sens, journal OD) dirait : on exige celui-là.
        foreach ($ecritures as $ecriture) {
            $ligne = TransactionLigne::where('transaction_id', $ecriture->id)
                ->where('compte_id', $origine->compte_id)
                ->sole();

            serviceRattachementAssertRefusee($ligne, 'écriture de rattachement');
        }
    })->with('rattachement_pca_cca');

    test('D14 — une ligne du journal des opérations diverses, qui n’est pas une écriture de rattachement, est refusée', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        Transaction::whereKey($ligne->transaction_id)->update(['journal' => JournalComptable::Od->value]);

        // La prémisse : ni sens inverse, ni écriture de rattachement. Seul le journal refuse.
        expect(Transaction::find($ligne->transaction_id)->provision_id)->toBeNull();

        serviceRattachementAssertRefusee($ligne, 'inventaire');
    })->with('rattachement_pca_cca');

    // D13 — la pièce annulée se juge sur la DATE COMPTABLE de l'annulation (celle de la
    // pièce miroir), pas sur l'horodatage de l'action (extournee_at, = now()). Annulée
    // dans N, rattacher l'origine retirerait deux fois le même montant ; annulée dans N+1
    // (un remboursement après le 31/08), rattacher est juste et c'est le refus qui
    // laisserait N faux. Une pièce qui EST une annulation ne se rattache jamais.
    // Trois déclencheurs distincts, chacun seul vrai dans son test.

    test('D13 — une pièce dont type_ecriture vaut extourne (une annulation) est refusée', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        Transaction::whereKey($ligne->transaction_id)->update(['type_ecriture' => 'extourne']);

        // Les autres déclencheurs sont faux.
        $piece = Transaction::find($ligne->transaction_id);
        expect($piece->extournee_at)->toBeNull();
        expect(Extourne::where('transaction_extourne_id', $piece->id)->exists())->toBeFalse();

        serviceRattachementAssertRefusee($ligne, 'annule une autre pièce');
    })->with('rattachement_pca_cca');

    test('D13 — une pièce référencée comme annulation dans la table des extournes est refusée', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        $pieceAnnulee = $this->ligneOrigine($classe, $montant);

        Extourne::create([
            'transaction_origine_id' => $pieceAnnulee->transaction_id,
            'transaction_extourne_id' => $ligne->transaction_id,
            'created_by' => $this->user->id,
        ]);

        $piece = Transaction::find($ligne->transaction_id);
        expect($piece->extournee_at)->toBeNull();
        expect($piece->type_ecriture)->toBe('normale');

        serviceRattachementAssertRefusee($ligne, 'annule une autre pièce');
    })->with('rattachement_pca_cca');

    test('D13 — la pièce annulée au plus tard le dernier jour de N, même avant sa propre date, est refusée', function (int $classe, string $dateAnnulation) {
        ['origine' => $ligneOrigine] = serviceRattachementPieceAnnulee($classe, $dateAnnulation);

        serviceRattachementAssertRefusee($ligneOrigine, 'annulée avant la fin de son exercice');
    })->with([
        'PCA annulée en cours d’exercice' => [7, '2026-04-20'],
        'PCA annulée avant l’exercice de la pièce' => [7, '2025-06-01'],
        'PCA annulée le dernier jour de N' => [7, '2026-08-31'],
        'CCA annulée le dernier jour de N' => [6, '2026-08-31'],
    ]);

    test('D13 — la pièce annulée dans l’exercice N+1 reste rattachable : motif nul, et rattacher() réussit', function (int $classe, string $dateAnnulation) {
        ['origine' => $ligneOrigine] = serviceRattachementPieceAnnulee($classe, $dateAnnulation);
        $service = app(RattachementExerciceService::class);

        // La prémisse : la pièce est bien annulée, extournee_at est posé (à now(), dans N).
        $piece = Transaction::find($ligneOrigine->transaction_id);
        expect($piece->extournee_at)->not->toBeNull();
        expect($piece->extournee_at->toDateString())->toBe('2026-01-15');

        expect($service->motifDeRefus($ligneOrigine->fresh()))->toBeNull();

        $provision = $service->rattacher($ligneOrigine->fresh(), null, $this->user);

        expect((int) $provision->exercice)->toBe(2025);
        expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
    })->with([
        'PCA annulée le premier jour de N+1' => [7, '2026-09-01'],
        'CCA annulée le premier jour de N+1' => [6, '2026-09-01'],
        'PCA annulée plus tard dans N+1' => [7, '2027-02-01'],
    ]);

    test('D13 — la ligne de l’annulation elle-même est refusée, quelle que soit la date de l’annulation', function (int $classe, string $dateAnnulation) {
        ['miroir' => $ligneMiroir] = serviceRattachementPieceAnnulee($classe, $dateAnnulation);

        // La prémisse : le miroir est bien une annulation, sur le même compte de produit ou de charge.
        expect($ligneMiroir->transaction->type_ecriture)->toBe('extourne');

        serviceRattachementAssertRefusee($ligneMiroir, 'annule une autre pièce');
    })->with([
        'PCA annulée dans N' => [7, '2026-04-20'],
        'PCA annulée dans N+1' => [7, '2026-09-01'],
        'CCA annulée dans N+1' => [6, '2026-09-01'],
    ]);

    test('D13 — une pièce annulée dont la date d’annulation est introuvable est refusée par prudence', function (string $cas) {
        if ($cas === 'sans enregistrement') {
            // extournee_at est posé, mais aucun enregistrement d'annulation ne permet de dater.
            $ligne = $this->ligneOrigine(7, 175.0);
            Transaction::whereKey($ligne->transaction_id)->update(['extournee_at' => now()]);
            expect(Extourne::count())->toBe(0);
        } else {
            // L'enregistrement existe, mais la pièce miroir a été supprimée.
            ['origine' => $ligne, 'miroir' => $ligneMiroir] = serviceRattachementPieceAnnulee(7, '2026-09-01');
            $ligneMiroir->transaction->delete();
            expect(Extourne::count())->toBe(1);
        }

        serviceRattachementAssertRefusee($ligne, 'introuvable');
    })->with([
        'sans enregistrement d’annulation' => ['sans enregistrement'],
        'pièce d’annulation supprimée' => ['miroir supprimé'],
    ]);

    test('une ligne sur un compte de classe 4, ou sans compte, est refusée : seule une charge ou un produit se rattache', function (?string $numero, float $debit, float $credit) {
        $compte = $numero === null ? null : Compte::where('numero_pcg', $numero)->firstOrFail();
        $ligne = serviceRattachementLigne($compte, $debit, $credit, TypeTransaction::Recette, JournalComptable::Vente);

        serviceRattachementAssertRefusee($ligne, 'charge ou de produit');
    })->with([
        '411 au débit' => ['411', 175.0, 0.0],
        '401 au crédit' => ['401', 0.0, 175.0],
        'sans compte' => [null, 0.0, 175.0],
    ]);

    test('la contrepartie (411, 401) d’une écriture d’inventaire ou d’une pièce annulée reçoit le motif de classe, qui la décrit', function (string $cas) {
        if ($cas === 'journal OD') {
            $compte = Compte::where('numero_pcg', '411')->firstOrFail();
            $ligne = serviceRattachementLigne($compte, 175.0, 0.0, TypeTransaction::Recette, JournalComptable::Od);
        } else {
            ['origine' => $ligneProduit] = serviceRattachementPieceAnnulee(7, '2026-04-20');
            $ligne = TransactionLigne::where('transaction_id', $ligneProduit->transaction_id)
                ->where('id', '!=', $ligneProduit->id)
                ->sole();
        }

        serviceRattachementAssertRefusee($ligne, 'charge ou de produit');
    })->with([
        'écriture d’inventaire' => ['journal OD'],
        'pièce annulée' => ['pièce annulée'],
    ]);

    test('AC-5 — une ligne de sens inverse (709A au débit, 609 au crédit) est refusée, et rien n’est créé', function (string $numero, float $debit, float $credit, string $attendu) {
        $compte = Compte::factory()->numero($numero)->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
        $estProduit = (int) $compte->classe === 7;
        $ligne = serviceRattachementLigne(
            $compte,
            $debit,
            $credit,
            $estProduit ? TypeTransaction::Recette : TypeTransaction::Depense,
            $estProduit ? JournalComptable::Vente : JournalComptable::Achat,
        );

        serviceRattachementAssertRefusee($ligne, $attendu);

        // Le motif dit ce que ferait le rattachement : il ne reporterait pas l'effet, il l'amplifierait.
        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne->fresh()))
            ->toContain('au lieu de le reporter sur l’exercice suivant');
    })->with([
        '709A (produit) au débit' => ['709A', 50.0, 0.0, 'sens inverse (709A au débit)'],
        '609 (charge) au crédit' => ['609', 0.0, 50.0, 'sens inverse (609 au crédit)'],
    ]);

    test('AC-6 — une ligne porteuse d’affectations est refusée avec le motif partagé, et n’est jamais traitée au grain ligne', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);

        foreach ([100.0, $montant - 100.0] as $part) {
            $operation = Operation::factory()->create([
                'association_id' => TenantContext::currentId(),
                'date_debut' => '2026-09-18',
                'date_fin' => '2027-06-18',
            ]);
            TransactionLigneAffectation::create([
                'transaction_ligne_id' => $ligne->id,
                'operation_id' => $operation->id,
                'montant' => $part,
            ]);
        }

        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne->fresh()))
            ->toBe(RattachementExerciceService::MOTIF_AFFECTATIONS);

        // Le vocabulaire de l'écran (« ventiler »), pas le nom de la table.
        expect(RattachementExerciceService::MOTIF_AFFECTATIONS)
            ->toContain('ventilée sur des opérations')
            ->not->toContain('affectations');

        serviceRattachementAssertRefusee($ligne, RattachementExerciceService::MOTIF_AFFECTATIONS);
    })->with([
        'PCA' => [7, 175.0],
        'CCA' => [6, 190.0],
    ]);

    test('une ligne déjà rattachée est refusée, et un second appel ne crée pas de second rattachement', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        expect(DB::table('provisions')->count())->toBe(1);

        serviceRattachementAssertRefusee($ligne, 'déjà rattachée');

        expect(DB::table('provisions')->count())->toBe(1);
        expect(Transaction::whereNotNull('provision_id')->count())->toBe(2);
    })->with('rattachement_pca_cca');
});

describe('RattachementExerciceService : création', function () {
    test('rattacher() crée le rattachement et ses deux écritures : exercice, montant, origine, notes et auteur', function (int $classe, float $montant, CasProvision $cas) {
        $ligne = $this->ligneOrigine($classe, $montant);

        $provision = app(RattachementExerciceService::class)->rattacher($ligne, 'Prestation de septembre', $this->user);

        expect($provision)->toBeInstanceOf(Provision::class);
        expect($provision->exists)->toBeTrue();
        expect((int) $provision->exercice)->toBe(2025);
        expect((float) $provision->montant)->toBe($montant);
        expect((int) $provision->transaction_ligne_id)->toBe((int) $ligne->id);
        expect($provision->notes)->toBe('Prestation de septembre');
        expect((int) $provision->saisi_par)->toBe((int) $this->user->id);
        expect($provision->cas())->toBe($cas);

        $ecritures = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();
        expect($ecritures)->toHaveCount(2);
        expect($ecritures->map(fn (Transaction $t) => $t->date->format('Y-m-d'))->all())->toBe(['2026-08-31', '2026-09-01']);
        expect($ecritures->pluck('type_ecriture')->all())->toBe(['normale', 'extourne']);

        // Le sens, en euros : la dotation corrige N, l'extourne le rend à N+1.
        $impactDotation = $classe === 7 ? -$montant : $montant;
        expect(impactSurLeResultat($ecritures[0]->load('lignes.compte')))->toBe($impactDotation);
        expect(impactSurLeResultat($ecritures[1]->load('lignes.compte')))->toBe(-$impactDotation);

        expect($ligne->fresh()->estRattachee())->toBeTrue();
    })->with([
        'PCA' => [7, 175.0, CasProvision::ProduitConstateDavance],
        'CCA' => [6, 90.0, CasProvision::ChargeConstateeDavance],
    ]);

    test('le libellé du rattachement reste nul : il est celui de la ligne d’origine, lu par libelleEffectif()', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        $provision = app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        expect($provision->libelle)->toBeNull();
        expect($provision->libelleEffectif())->toBe('Ligne d’origine');
    });

    test('rattacherParId() résout la ligne dans l’association courante et rattache', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        $provision = app(RattachementExerciceService::class)->rattacherParId((int) $ligne->id, null, $this->user);

        expect((int) $provision->transaction_ligne_id)->toBe((int) $ligne->id);
        expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
    });

    test('la création est journalisée : rattachement, ligne, cas, exercice et montant', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $spy = Log::spy();

        $provision = app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        $attendu = [
            'provision_id' => (int) $provision->id,
            'transaction_ligne_id' => (int) $ligne->id,
            'cas' => 'pca',
            'exercice' => 2025,
            'montant' => 175.0,
        ];
        ksort($attendu);

        $spy->shouldHaveReceived('info')
            ->with('[Rattachement] écriture de rattachement créée', Mockery::on(function ($contexte) use ($attendu) {
                ksort($contexte);

                return $contexte === $attendu;
            }))
            ->once();
    });

    test('sans aucune ligne exercices pour N+1, le rattachement réussit et l’extourne est générée', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect(Exercice::where('annee', 2026)->exists())->toBeFalse();

        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        $extourne = Transaction::where('provision_id', $provision->id)->where('type_ecriture', 'extourne')->sole();
        expect($extourne->date->format('Y-m-d'))->toBe('2026-09-01');
        expect(Exercice::where('annee', 2026)->exists())->toBeFalse();
    });

    test('AC-9 — la création est refusée si N est clôturé, ou si N+1 est clôturé, et rien n’est créé', function (int $anneeCloturee, string $libelle) {
        Exercice::create(['annee' => $anneeCloturee, 'statut' => StatutExercice::Cloture]);
        $ligne = $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        expect(fn () => app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user))
            ->toThrow(ExerciceCloturedException::class, $libelle);

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
        expect($ligne->fresh()->estRattachee())->toBeFalse();
    })->with([
        'N (2025-2026) clôturé' => [2025, '2025-2026'],
        'N+1 (2026-2027) clôturé' => [2026, '2026-2027'],
    ]);

    test('l’exercice du rattachement est celui de la date de la pièce, pas celui d’aujourd’hui', function (string $date, int $exercice) {
        // « Aujourd'hui » est figé au 2026-01-15 (exercice 2025) : une pièce d'un autre
        // exercice ne peut être bien classée que par sa propre date.
        $ligne = $this->ligneOrigine(7, 175.0);
        Transaction::whereKey($ligne->transaction_id)->update(['date' => $date]);

        $provision = app(RattachementExerciceService::class)->rattacher($ligne->fresh(), null, $this->user);

        expect((int) $provision->exercice)->toBe($exercice);

        $ecritures = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();
        expect($ecritures->map(fn (Transaction $t) => $t->date->format('Y-m-d'))->all())
            ->toBe([($exercice + 1).'-08-31', ($exercice + 1).'-09-01']);
    })->with([
        'avril 2025 : exercice 2024' => ['2025-04-08', 2024],
        'veille de la bascule : exercice 2024' => ['2025-08-31', 2024],
        'premier jour de l’exercice 2025' => ['2025-09-01', 2025],
        'dernier jour de l’exercice 2025' => ['2026-08-31', 2025],
    ]);

    test('les verrous sont pris dans l’ordre canonique — association, exercice N, exercice N+1, pièce, ligne — puis affectations et « déjà rattachée » sont relues sous verrou, avant toute écriture', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        // Seules comptent les requêtes qui précèdent la première écriture : les verrous
        // sont posés avant que l'écriture de rattachement ne commence.
        $avantEcriture = serviceRattachementRequetesAvantEcriture(
            fn () => app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user)
        );

        $indices = [
            'association' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'association'),
            'exercice N' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'exercices', 2025),
            'exercice N+1' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'exercices', 2026),
            'pièce' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'transactions'),
            'ligne' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'transaction_lignes'),
            // L'éligibilité est rejugée sous verrou : les affectations, que ni la pièce ni la
            // ligne ne protègent, se lisent elles aussi sous verrou, puis « déjà rattachée ».
            'affectations' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'transaction_ligne_affectations'),
            // « Déjà rattachée », relu sous les verrous : après la ligne, avant l'écriture.
            'recontrôle' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'provisions'),
        ];

        // Strictement croissants : chaque verrou est pris après le précédent.
        expect(array_values($indices))->toBe(collect($indices)->sort()->values()->all());
        expect(count(array_unique($indices)))->toBe(7);

        // Les deux relectures de l'éligibilité sont des lectures verrouillantes DIRECTES, pas des
        // EXISTS : un FOR UPDATE placé dans une sous-requête ne prouve rien du verrou posé.
        foreach (['affectations', 'recontrôle'] as $cle) {
            expect(mb_strtolower($avantEcriture[$indices[$cle]]->sql))->not->toContain('exists');
        }

        // SQLite n'écrit pas « for update » : seul MySQL/MariaDB prouve que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($indices as $i) {
                expect($avantEcriture[$i]->sql)->toContain('for update');
            }
        }
    });

    test('AC-12 — l’identifiant d’une ligne d’une autre association est traité comme inexistant', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $associationDeLaLigne = TenantContext::current();

        TenantContext::clear();
        TenantContext::boot(Association::factory()->create());

        $transactionsAvant = DB::table('transactions')->count();

        expect(fn () => app(RattachementExerciceService::class)->rattacherParId((int) $ligne->id, null, $this->user))
            ->toThrow(RuntimeException::class, 'n’existe pas');

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);

        TenantContext::clear();
        TenantContext::boot($associationDeLaLigne);

        expect($ligne->fresh()->estRattachee())->toBeFalse();
    });

    test('un identifiant de ligne inexistant est refusé comme une ligne d’une autre association', function () {
        $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        expect(fn () => app(RattachementExerciceService::class)->rattacherParId(999999, null, $this->user))
            ->toThrow(RuntimeException::class, 'n’existe pas');

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
    });

    // I1 — la ligne et sa pièce sont verrouillées dans la transaction, puis JUGÉES dans cet
    // état verrouillé : ni le montant ni l'éligibilité ne viennent de l'état lu avant. Un
    // écrivain qui ne prend pas le verrou d'association (synchronisation HelloAsso, édition,
    // annulation) peut avoir changé la ligne entre motifDeRefus() et le verrou. Chaque test
    // fait ce changement DANS la transaction du service, sur sa toute première requête (le
    // verrou d'association) : c'est exactement la fenêtre que le verrou de pièce et de ligne
    // doit fermer.

    test('I1 — le montant du rattachement est celui de la ligne verrouillée, pas celui lu avant', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        // La synchronisation HelloAsso (remboursement partiel) ramène la ligne à 100 €.
        $provision = serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->update(['credit' => 100, 'montant' => 100]),
            $this->user,
        );

        expect((float) $provision->montant)->toBe(100.0);

        $ecritures = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();
        expect($ecritures)->toHaveCount(2);
        expect(impactSurLeResultat($ecritures[0]->load('lignes.compte')))->toBe(-100.0);
        expect(impactSurLeResultat($ecritures[1]->load('lignes.compte')))->toBe(100.0);
    });

    test('I1 — une ligne recréée ou supprimée entre-temps est refusée lisiblement, pas par une erreur technique', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        // TransactionService::update() détruit et recrée toutes les lignes de la pièce.
        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->delete(),
            $this->user,
        ))->toThrow(RuntimeException::class, 'Cette ligne n’existe plus : sa pièce a été modifiée entre-temps. Rechargez la page.');

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
    });

    test('I1 — une pièce supprimée entre-temps est refusée lisiblement', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transactions')->where('id', $ligne->transaction_id)->update(['deleted_at' => now()]),
            $this->user,
        ))->toThrow(RuntimeException::class, 'La pièce de cette ligne a été supprimée entre-temps. Rechargez la page.');

        expect(DB::table('provisions')->count())->toBe(0);
    });

    test('I1 — une ligne passée à une autre pièce entre-temps est refusée lisiblement', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $autrePiece = $this->ligneOrigine(7, 50.0);

        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->update(['transaction_id' => $autrePiece->transaction_id]),
            $this->user,
        ))->toThrow(RuntimeException::class, 'Cette ligne n’existe plus : sa pièce a été modifiée entre-temps. Rechargez la page.');

        expect(DB::table('provisions')->count())->toBe(0);
    });

    test('I1 — l’éligibilité est rejugée sur la pièce verrouillée : annulée (vraie annulation, ou date introuvable), ou passée en journal d’opérations diverses, entre-temps', function (string $cas, string $fragment) {
        ['origine' => $ligne, 'piece' => $piece] = serviceRattachementPieceAAnnuler(7);
        $transactionsAvant = DB::table('transactions')->count();

        // Prémisse : avant le changement, la ligne est rattachable.
        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne))->toBeNull();

        $changement = match ($cas) {
            // Une vraie annulation, datée dans N : D13 relit ses relations (l'annulation et sa
            // pièce miroir) sous verrou, sans quoi elle ne verrait rien.
            'annulée dans N' => fn () => serviceRattachementAnnuler($piece, '2026-04-20'),
            // extournee_at posé sans annulation retrouvable : la date est introuvable.
            'date introuvable' => fn () => DB::table('transactions')->where('id', $piece->id)->update(['extournee_at' => '2026-02-01 10:00:00']),
            'journal OD' => fn () => DB::table('transactions')->where('id', $piece->id)->update(['journal' => 'od']),
        };

        expect(fn () => serviceRattachementAvecChangementConcurrent($ligne, $changement, $this->user))
            ->toThrow(RuntimeException::class, $fragment);

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
    })->with([
        'annulée dans N par une vraie annulation' => ['annulée dans N', 'avant la fin de son exercice'],
        'annulée, date d’annulation introuvable' => ['date introuvable', 'introuvable'],
        'passée en journal d’opérations diverses' => ['journal OD', 'inventaire'],
    ]);

    test('I1 — l’éligibilité est rejugée sur la ligne verrouillée : reclassée sur un compte de classe 4, ou passée de sens, entre-temps', function (string $changement, string $fragment) {
        $ligne = $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        // Prémisse : avant le changement, la ligne est rattachable (et ses relations sont chargées).
        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne))->toBeNull();

        $modification = $changement === 'compte'
            ? ['compte_id' => Compte::where('numero_pcg', '411')->firstOrFail()->id]
            : ['credit' => 0, 'debit' => 175];

        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->update($modification),
            $this->user,
        ))->toThrow(RuntimeException::class, $fragment);

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
    })->with([
        'reclassée sur un 411' => ['compte', 'charge ou de produit'],
        'passée au débit (sens inverse)' => ['sens', 'sens inverse'],
    ]);

    test('I1 — une pièce redatée dans un autre exercice entre-temps est refusée lisiblement', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        // Les exercices verrouillés sont ceux de l'ancienne date : le rattachement ne peut
        // pas se poursuivre sous des verrous qui ne sont plus les bons.
        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transactions')->where('id', $ligne->transaction_id)->update(['date' => '2025-04-08']),
            $this->user,
        ))->toThrow(RuntimeException::class, 'La date de la pièce a changé');

        expect(DB::table('provisions')->count())->toBe(0);
    });

    test('l’écriture porte la ligne jugée sous verrou : son libellé courant, sans relire la ligne hors verrou', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $niveauAvant = DB::transactionLevel();
        $requetes = [];

        DB::listen(function ($requete) use (&$requetes, $niveauAvant): void {
            if (DB::transactionLevel() > $niveauAvant) {
                $requetes[] = mb_strtolower(str_replace(['`', '"'], '', $requete->sql));
            }
        });

        // La synchronisation HelloAsso corrige le libellé de la ligne entre le contrôle et le verrou.
        $provision = serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->update(['libelle' => 'Libellé corrigé']),
            $this->user,
        );

        foreach (Transaction::where('provision_id', $provision->id)->get() as $ecriture) {
            expect($ecriture->libelle)->toEndWith(' : Libellé corrigé');
        }

        // Ce que le libellé seul ne prouve pas, les tests tournant sur une seule connexion (une
        // relecture y verrait elle aussi le libellé corrigé) : la ligne jugée est celle de
        // l'écriture, elle n'est pas relue. Après l'insertion du rattachement, plus aucune
        // lecture de transaction_lignes par identifiant, ce que ferait $provision->ligne si la
        // relation n'était pas posée.
        $insertion = collect($requetes)->search(fn (string $sql) => str_starts_with($sql, 'insert into provisions'));
        expect($insertion)->toBeInt();

        $relectures = array_filter(
            array_slice($requetes, $insertion),
            fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'transaction_lignes.id = ?'),
        );
        expect($relectures)->toBe([]);
    });

    test('les notes vides ou blanches deviennent nulles, les autres sont rognées', function (?string $notes, ?string $attendu) {
        $ligne = $this->ligneOrigine(7, 175.0);

        $provision = app(RattachementExerciceService::class)->rattacher($ligne, $notes, $this->user);

        expect($provision->fresh()->notes)->toBe($attendu);
    })->with([
        'nulles' => [null, null],
        'vides' => ['', null],
        'blanches' => ["  \n ", null],
        'rognées' => ['  Prestation de septembre ', 'Prestation de septembre'],
    ]);

    test('le recontrôle « déjà rattachée » a lieu dans la transaction, après le verrou de la ligne : jamais deux rattachements', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $niveauAvant = DB::transactionLevel();
        $injecte = false;

        // Fait apparaître un rattachement de la même ligne DANS la transaction du
        // service, juste après sa première lecture de transaction_lignes — le verrou de
        // la ligne (le test d'ordre des verrous établit que c'est la première). Le
        // contrôle de motifDeRefus(), fait avant la transaction, a déjà conclu que la ligne
        // était libre : seul un recontrôle placé après ce verrou peut voir le rattachement.
        // Un recontrôle déplacé avant la transaction, ou supprimé, fait tomber ce test : le
        // service créerait un second rattachement au lieu de lever.
        //
        // Ce que le test ne prouve pas : l'exclusion mutuelle de deux requêtes. C'est le
        // moteur qui la fournit, par les verrous (association, pièce, puis ligne) ; une vraie
        // concurrence ne se provoque pas dans un seul processus. Il prouve où est le
        // recontrôle et ce qu'il fait de ce qu'il trouve.
        DB::listen(function ($requete) use (&$injecte, $ligne, $niveauAvant): void {
            if ($injecte
                || DB::transactionLevel() <= $niveauAvant
                || ! str_contains($requete->sql, 'transaction_lignes')) {
                return;
            }

            $injecte = true;
            provisionPourLigne($ligne);
        });

        expect(fn () => app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user))
            ->toThrow(RuntimeException::class, 'déjà rattachée');

        expect($injecte)->toBeTrue();
        // Le rattachement injecté vivait dans la transaction du service : son refus
        // l'annule avec elle. Il ne reste ni rattachement ni écriture.
        expect(DB::table('provisions')->count())->toBe(0);
        expect(Transaction::whereNotNull('provision_id')->count())->toBe(0);
    });
});

describe('RattachementExerciceService : retrait', function () {
    // Le retrait supprime les deux écritures, supprime PHYSIQUEMENT la ligne de provisions
    // (le modèle n'a pas de suppression logique : une ligne de provisions existe si et
    // seulement si la ligne d'origine est verrouillée), et ne touche à rien d'autre. Chaque
    // test compte ce que le rattachement possède en base (serviceRattachementEtat) : un état
    // posé vaut 1 / 2 / 4 (rattachement, écritures, lignes d'écriture), un retrait réussi le
    // ramène à 0 / 0 / 0, un retrait refusé le laisse tel quel.

    test('retirer() supprime les deux écritures et leurs lignes, supprime physiquement le rattachement et libère la ligne d’origine', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);

        // Un autre rattachement, qui ne doit pas être touché : le retrait vise le sien seul.
        $autreLigne = $this->ligneOrigine($classe, $montant);
        $autre = serviceRattachementRattachee($autreLigne);
        $autreEcritureIds = serviceRattachementEcritureIds($autre);

        // Prémisse (anti-test-vide) : tout est là, la ligne est verrouillée des deux façons.
        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);
        expect($ligne->fresh()->estRattachee())->toBeTrue();
        expect($ligne->fresh()->estRattacheeSousVerrou())->toBeTrue();

        app(RattachementExerciceService::class)->retirer($provision);

        // Compté par identifiant et sans filtre : une écriture qui survivrait (son provision_id
        // passé à NULL par la clé étrangère) ou une simple suppression logique se verraient ici.
        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 0, 'ecritures' => 0, 'lignes' => 0]);
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeFalse();

        expect($ligne->fresh()->estRattachee())->toBeFalse();
        expect($ligne->fresh()->estRattacheeSousVerrou())->toBeFalse();

        // L'origine est intacte : la pièce, sa ligne, son montant.
        expect(Transaction::whereKey($ligne->transaction_id)->exists())->toBeTrue();
        expect((float) TransactionLigne::findOrFail($ligne->id)->montant)->toBe($montant);

        // L'autre rattachement est intact.
        expect(serviceRattachementEtat($autre, $autreEcritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);
        expect($autreLigne->fresh()->estRattachee())->toBeTrue();
    })->with('rattachement_pca_cca');

    test('AC-9 — le retrait est refusé si N est clôturé, ou si N+1 est clôturé, et rien n’a bougé', function (int $anneeCloturee, string $libelle) {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);

        // La clôture vient APRÈS le rattachement : à la création, N et N+1 doivent être ouverts.
        Exercice::create(['annee' => $anneeCloturee, 'statut' => StatutExercice::Cloture]);

        expect(fn () => app(RattachementExerciceService::class)->retirer($provision))
            ->toThrow(ExerciceCloturedException::class, $libelle);

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);
        expect($ligne->fresh()->estRattachee())->toBeTrue();
    })->with([
        'N (2025-2026) clôturé' => [2025, '2025-2026'],
        'N+1 (2026-2027) clôturé' => [2026, '2026-2027'],
    ]);

    test('seuls N et N+1 comptent : la clôture de l’exercice précédent ou du suivant n’empêche pas le retrait', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);

        Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Cloture]);
        Exercice::create(['annee' => 2027, 'statut' => StatutExercice::Cloture]);

        app(RattachementExerciceService::class)->retirer($provision);

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 0, 'ecritures' => 0, 'lignes' => 0]);
    });

    test('les exercices contrôlés sont ceux du rattachement, pas la date que porte la pièce d’origine aujourd’hui', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);

        // Les écritures du rattachement vivent dans 2025 (dotation) et 2026 (extourne), quoi qu'il
        // arrive ensuite à la pièce : on la redate (ce qu'un autre chemin d'écriture aurait pu faire)
        // dans l'exercice 2024, dont ni N ni N+1 ne seraient clôturés. Seule la clôture de 2026 le dit.
        DB::table('transactions')->where('id', $ligne->transaction_id)->update(['date' => '2025-04-08']);
        Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

        expect(fn () => app(RattachementExerciceService::class)->retirer($provision))
            ->toThrow(ExerciceCloturedException::class, '2026-2027');

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);
    });

    test('AC-10 — un rattachement retiré peut être repassé sur la même ligne : un seul rattachement, deux nouvelles écritures', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        $service = app(RattachementExerciceService::class);

        $premier = $service->rattacher($ligne, null, $this->user);
        $anciennesEcritures = serviceRattachementEcritureIds($premier);
        $service->retirer($premier);

        // Redevenue éligible : plus de « déjà rattachée ».
        expect($service->motifDeRefus($ligne->fresh()))->toBeNull();

        $second = $service->rattacher($ligne->fresh(), null, $this->user);

        expect((int) $second->id)->not->toBe((int) $premier->id);
        expect((int) $second->transaction_ligne_id)->toBe((int) $ligne->id);
        expect(DB::table('provisions')->count())->toBe(1);

        $nouvellesEcritures = serviceRattachementEcritureIds($second);
        expect($nouvellesEcritures)->toHaveCount(2);
        expect(array_intersect($anciennesEcritures, $nouvellesEcritures))->toBe([]);

        // Rien d'autre ne subsiste de la première fois : deux écritures de rattachement, pas quatre.
        expect(DB::table('transactions')->whereNotNull('provision_id')->count())->toBe(2);

        $dotation = Transaction::where('provision_id', $second->id)->where('type_ecriture', 'normale')->sole();
        expect(impactSurLeResultat($dotation->load('lignes.compte')))->toBe($classe === 7 ? -$montant : $montant);
        expect($ligne->fresh()->estRattachee())->toBeTrue();
    })->with('rattachement_pca_cca');

    test('AC-10 — après un retrait, TransactionService::update() sur la pièce d’origine réussit ; avant, il échoue sans rien changer', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);

        // Le geste réel : corriger le libellé de la pièce. update() détruit puis recrée toutes
        // les lignes de la pièce (forceDelete), ce que la clé étrangère refuse tant qu'un
        // rattachement les référence.
        $donnees = [
            'type' => $classe === 7 ? 'recette' : 'depense',
            'date' => '2026-04-08',
            'libelle' => 'Pièce corrigée',
            'montant_total' => number_format($montant, 2, '.', ''),
            'mode_paiement' => null,
            'tiers_id' => null,
            'compte_id' => null,
        ];
        $lignes = [[
            'id' => (int) $ligne->id,
            'compte_id' => (int) $ligne->compte_id,
            'montant' => $montant,
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]];
        $corriger = fn (): Transaction => app(TransactionService::class)->update(
            Transaction::findOrFail($ligne->transaction_id),
            $donnees,
            $lignes,
        );

        // Témoin : AVANT le retrait, update() échoue — aujourd'hui sur l'erreur de clé étrangère
        // de la base. La Task 8 en fera un refus lisible (AC-8) et resserrera cette assertion ;
        // ici on ne fige pas le type d'exception, seulement l'échec et l'absence d'effet.
        // Pas de toThrow(Throwable::class) : Pest ne reconnaît que des classes, et prendrait
        // l'interface pour un fragment de message attendu.
        $echec = null;

        try {
            $corriger();
        } catch (Throwable $e) {
            $echec = $e;
        }

        expect($echec)->toBeInstanceOf(Throwable::class);

        expect(Transaction::findOrFail($ligne->transaction_id)->libelle)->toBe('Pièce d’origine');
        expect(TransactionLigne::find($ligne->id))->not->toBeNull();
        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);

        app(RattachementExerciceService::class)->retirer($provision);

        // APRÈS le retrait, le même geste réussit : c'est le test qui aurait attrapé la
        // contradiction entre la suppression logique du rattachement et la clé étrangère.
        $piece = $corriger();

        expect($piece->libelle)->toBe('Pièce corrigée');
        expect(Transaction::findOrFail($ligne->transaction_id)->libelle)->toBe('Pièce corrigée');
        // update() a recréé la ligne : elle existe toujours, avec un autre identifiant.
        expect(TransactionLigne::where('transaction_id', $ligne->transaction_id)->count())->toBe(1);
        expect(TransactionLigne::find($ligne->id))->toBeNull();
    })->with('rattachement_pca_cca');

    test('retirer() deux fois de suite : la seconde lève « déjà retiré » et ne touche à rien', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $service = app(RattachementExerciceService::class);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);

        $service->retirer($provision);

        // Même instance, périmée : sa ligne n'existe plus en base. Seul le verrou posé sur le
        // rattachement, relu sous verrou, le voit.
        expect(fn () => $service->retirer($provision))
            ->toThrow(RuntimeException::class, 'Ce rattachement n’existe plus : il a peut-être déjà été retiré. Rechargez la page.');

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 0, 'ecritures' => 0, 'lignes' => 0]);
    });

    test('un rattachement d’une autre association est traité comme déjà retiré : rien n’est supprimé, ni lui ni ses écritures', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);
        $associationDuRattachement = TenantContext::current();

        // L'instance est celle d'une autre association : sans le scope tenant sur le verrou, le
        // retrait supprimerait la ligne de provisions, tandis que ses écritures (que l'autre
        // association ne voit pas) resteraient, orphelines.
        TenantContext::clear();
        TenantContext::boot(Association::factory()->create());

        expect(fn () => app(RattachementExerciceService::class)->retirer($provision))
            ->toThrow(RuntimeException::class, 'Ce rattachement n’existe plus : il a peut-être déjà été retiré. Rechargez la page.');

        TenantContext::clear();
        TenantContext::boot($associationDuRattachement);

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);
        expect($ligne->fresh()->estRattachee())->toBeTrue();
    });

    test('retirerParId() retire le rattachement désigné par son identifiant : écritures, ligne de provisions, et la ligne d’origine est libérée', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);

        // Un autre rattachement, qui ne doit pas être touché : le retrait vise le sien seul.
        $autre = serviceRattachementRattachee($this->ligneOrigine($classe, $montant));
        $autreEcritureIds = serviceRattachementEcritureIds($autre);

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);

        app(RattachementExerciceService::class)->retirerParId((int) $provision->id);

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 0, 'ecritures' => 0, 'lignes' => 0]);
        expect($ligne->fresh()->estRattachee())->toBeFalse();
        expect(serviceRattachementEtat($autre, $autreEcritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);
    })->with('rattachement_pca_cca');

    test('retirerParId() d’un rattachement d’une autre association est refusé comme un rattachement disparu : rien n’est supprimé, et aucune transaction n’est ouverte', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);
        $associationDuRattachement = TenantContext::current();

        TenantContext::clear();
        TenantContext::boot(Association::factory()->create());

        // Aucune requête dans une transaction : le refus vient de la résolution de l'identifiant
        // sous le scope tenant, avant tout verrou. Sans cette assertion, le test passerait aussi
        // quand la résolution ne filtre pas : retirer() relit alors le rattachement sous le même
        // scope et lève le même message, après avoir ouvert une transaction et pris des verrous.
        $requetes = verrouRequetesDeLaTransaction(
            fn () => expect(fn () => app(RattachementExerciceService::class)->retirerParId((int) $provision->id))
                ->toThrow(RuntimeException::class, 'Ce rattachement n’existe plus : il a peut-être déjà été retiré. Rechargez la page.')
        );
        expect($requetes)->toBeEmpty();

        TenantContext::clear();
        TenantContext::boot($associationDuRattachement);

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);
        expect($ligne->fresh()->estRattachee())->toBeTrue();
    });

    test('retirerParId() d’un identifiant inexistant est refusé comme un rattachement d’une autre association', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);

        expect(fn () => app(RattachementExerciceService::class)->retirerParId(999999))
            ->toThrow(RuntimeException::class, 'Ce rattachement n’existe plus : il a peut-être déjà été retiré. Rechargez la page.');

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);
    });

    test('un rattachement dont l’origine a été supprimée logiquement reste retirable : c’est la seule sortie', function (bool $pieceSupprimee, bool $ligneSupprimee) {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);
        $ecritureIds = serviceRattachementEcritureIds($provision);
        $pieceId = (int) $ligne->transaction_id;

        // Suppression LOGIQUE (SoftDeletes) : la FK `restrictOnDelete` interdit la suppression
        // physique tant que le rattachement existe, et c'est justement le rattachement qu'on retire.
        if ($pieceSupprimee) {
            Transaction::findOrFail($pieceId)->delete();
        }
        if ($ligneSupprimee) {
            $ligne->delete();
        }

        // Prémisses : l'origine est bien masquée par ses suppressions logiques, et toujours là en base.
        expect(Transaction::find($pieceId) === null)->toBe($pieceSupprimee);
        expect(TransactionLigne::find($ligne->id) === null)->toBe($ligneSupprimee);
        expect(Transaction::withTrashed()->find($pieceId))->not->toBeNull();
        expect(TransactionLigne::withTrashed()->find($ligne->id))->not->toBeNull();
        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 1, 'ecritures' => 2, 'lignes' => 4]);

        app(RattachementExerciceService::class)->retirer($provision);

        expect(serviceRattachementEtat($provision, $ecritureIds))->toBe(['provision' => 0, 'ecritures' => 0, 'lignes' => 0]);

        // L'origine n'est ni ressuscitée ni purgée, et la ligne n'est plus verrouillée.
        expect(Transaction::withTrashed()->find($pieceId)->trashed())->toBe($pieceSupprimee);
        expect(TransactionLigne::withTrashed()->find($ligne->id)->trashed())->toBe($ligneSupprimee);
        expect(TransactionLigne::withTrashed()->find($ligne->id)->estRattachee())->toBeFalse();
    })->with([
        'pièce supprimée' => [true, false],
        'ligne supprimée' => [false, true],
        'pièce et ligne supprimées' => [true, true],
    ]);

    test('un rattachement qui ne s’appuie sur aucune ligne d’origine (saisie libre) n’est pas retirable : refus lisible, rien ne bouge', function () {
        $compte = Compte::factory()->recette()->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
        $libre = Provision::create([
            'association_id' => (int) TenantContext::currentId(),
            'exercice' => 2025,
            'compte_id' => $compte->id,
            'libelle' => 'Produit à recevoir',
            'montant' => 100,
            'saisi_par' => auth()->id(),
        ]);

        // Prémisse : c'est bien une saisie libre, sans ligne d'origine.
        expect($libre->estAdossee())->toBeFalse();

        expect(fn () => app(RattachementExerciceService::class)->retirer($libre))
            ->toThrow(RuntimeException::class, 'Ce rattachement est une saisie libre : son retrait n’est pas encore pris en charge.');

        expect(DB::table('provisions')->where('id', $libre->id)->exists())->toBeTrue();
    });

    test('les verrous du retrait sont pris dans l’ordre canonique — association, exercice N, exercice N+1, pièce, ligne, rattachement — avant toute suppression', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);

        $avantEcriture = serviceRattachementRequetesAvantEcriture(
            fn () => app(RattachementExerciceService::class)->retirer($provision)
        );

        $indices = [
            'association' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'association'),
            'exercice N' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'exercices', 2025),
            'exercice N+1' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'exercices', 2026),
            'pièce' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'transactions'),
            'ligne' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'transaction_lignes'),
            // Le rattachement en dernier, comme rattacher() et les gardes des Tasks 7 à 9, qui
            // lisent `provisions` après la pièce et la ligne : le verrouiller d'abord
            // s'inter-bloquerait avec elles.
            'rattachement' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'provisions'),
        ];

        // Strictement croissants : chaque verrou est pris après le précédent.
        expect(array_values($indices))->toBe(collect($indices)->sort()->values()->all());
        expect(count(array_unique($indices)))->toBe(6);

        // Une lecture verrouillante DIRECTE du rattachement, pas un EXISTS.
        expect(mb_strtolower($avantEcriture[$indices['rattachement']]->sql))->not->toContain('exists');

        // SQLite n'écrit pas « for update » : seul MySQL/MariaDB prouve que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($indices as $i) {
                expect($avantEcriture[$i]->sql)->toContain('for update');
            }
        }
    });

    test('pour une origine supprimée logiquement, la pièce et la ligne sont verrouillées quand même, dans l’ordre, avant le rattachement : leurs lectures ne filtrent pas sur deleted_at', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);
        $pieceId = (int) $ligne->transaction_id;
        Transaction::findOrFail($pieceId)->delete();
        $ligne->delete();

        $avantEcriture = serviceRattachementRequetesAvantEcriture(
            fn () => app(RattachementExerciceService::class)->retirer($provision)
        );

        $indices = [
            'pièce' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'transactions'),
            'ligne' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'transaction_lignes'),
            'rattachement' => serviceRattachementIndiceDeLaPremiere($avantEcriture, 'provisions'),
        ];

        // Même ordre qu'avec une origine intacte. Une pièce que la lecture hors transaction n'aurait
        // pas retrouvée (ligne supprimée logiquement) ne serait pas verrouillée du tout : la première
        // lecture de `transactions` serait alors celle des écritures, après le rattachement.
        expect(array_values($indices))->toBe(collect($indices)->sort()->values()->all());
        expect(count(array_unique($indices)))->toBe(3);

        // Chaque lecture vise la pièce ou la ligne d'origine (leur identifiant est le premier
        // paramètre, celui de whereKey()), et ne filtre pas sur deleted_at : une lecture qui exclut
        // les suppressions logiques ne trouverait rien à verrouiller, et le retrait se ferait sans
        // l'exclusion avec ce qui écrit la pièce ou la ligne.
        foreach (['pièce' => $pieceId, 'ligne' => (int) $ligne->id] as $cle => $id) {
            $requete = $avantEcriture[$indices[$cle]];

            expect($requete->bindings[0] ?? null)->toBe($id);
            expect($requete->sql)->not->toContain('deleted_at');

            if (DB::getDriverName() !== 'sqlite') {
                expect($requete->sql)->toContain('for update');
            }
        }
    });

    test('le retrait est journalisé APRÈS le commit : rattachement, ligne d’origine, exercice et montant', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = serviceRattachementRattachee($ligne);

        $niveauAuLog = null;
        $spy = Log::spy();
        $spy->shouldReceive('info')->andReturnUsing(function () use (&$niveauAuLog): void {
            $niveauAuLog = DB::transactionLevel();
        });
        $niveauAvant = DB::transactionLevel();

        app(RattachementExerciceService::class)->retirer($provision);

        $attendu = [
            'provision_id' => (int) $provision->id,
            'transaction_ligne_id' => (int) $ligne->id,
            'exercice' => 2025,
            'montant' => 175.0,
        ];
        ksort($attendu);

        $spy->shouldHaveReceived('info')
            ->with('[Rattachement] écriture de rattachement retirée', Mockery::on(function ($contexte) use ($attendu) {
                ksort($contexte);

                return $contexte === $attendu;
            }))
            ->once();

        // Hors de la transaction du service : un retrait validé ne doit pas pouvoir être
        // journalisé puis annulé, ni échouer parce que le journal échoue.
        expect($niveauAuLog)->toBe($niveauAvant);
    });

    test('un retrait refusé n’est pas journalisé', function (string $cas) {
        $ligne = $this->ligneOrigine(7, 175.0);
        $service = app(RattachementExerciceService::class);
        $provision = serviceRattachementRattachee($ligne);

        if ($cas === 'exercice clôturé') {
            Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);
        } elseif ($cas === 'déjà retiré') {
            $service->retirer($provision);
        } else {
            $compte = Compte::factory()->recette()->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
            $provision = Provision::create([
                'association_id' => (int) TenantContext::currentId(),
                'exercice' => 2025,
                'compte_id' => $compte->id,
                'libelle' => 'Produit à recevoir',
                'montant' => 100,
                'saisi_par' => auth()->id(),
            ]);
        }

        $spy = Log::spy();

        expect(fn () => $service->retirer($provision))->toThrow(RuntimeException::class);

        $spy->shouldNotHaveReceived('info', ['[Rattachement] écriture de rattachement retirée', Mockery::any()]);
    })->with([
        'exercice clôturé' => ['exercice clôturé'],
        'déjà retiré' => ['déjà retiré'],
        'saisie libre' => ['saisie libre'],
    ]);
});
