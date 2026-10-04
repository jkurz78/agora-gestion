<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\GelRattachement;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\ProvisionPDService;
use App\Services\Compta\RattachementExerciceService;
use App\Services\Compta\ReclassementLigneService;
use App\Services\Compta\VerrouLotsCanoniques;
use App\Services\ExerciceService;
use App\Services\TransactionExtourneService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Le gel d'une ligne rattachée à l'exercice (CCA/PCA) : les gardes elles-mêmes
|--------------------------------------------------------------------------
|
| Une ligne portée par un rattachement gèle sa pièce entière (spec § 7).
| TransactionLigne::estRattachee() est la source de vérité de ce verrou, lue sous verrou par
| estRattacheeSousVerrou() : la garde vit dans GelRattachement, dans assertLigneNonGelee() (un geste
| qui écrit sur une ligne) et verrouillerPieceNonGelee() (un geste qui réécrit la pièce entière). Ce
| fichier éprouve ces deux gardes appelées directement ; les tests des SITES qui les appellent sont
| un fichier par famille : RattachementVerrouTransactionTest.php (édition, suppression, annulation),
| RattachementVerrouReclassementFormulaireTest.php (reclassement, retrait de ligne, ouverture gelée du
| formulaire), RattachementVerrouVentilationTest.php (ventilation) et RattachementVerrouExtourneTest.php
| (l'annulation par extourne, seule à dépendre d'une date). Ce fichier éprouve aussi le graphe d'injection du gel
| (pas de cycle, pas d'écriture comptable dans la chaîne de construction des gestes protégés) et le motif de
| gel lu sans verrou pour prévenir l'écran, motifDeGel().
|
| Spec : docs/specs/2026-09-30-provisions-rattachement-exercice-design.md (§ 6.4, § 7).
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486 et 487 : les comptes de régularisation, posés par le seeder système. Aucun test
    // de ce fichier n'en a encore besoin (le verrou ne génère aucune écriture), mais les
    // gardes des Tasks 7 à 9 s'éprouveront sur des rattachements dont les écritures
    // existent : le décor est posé une fois pour toutes ici.
    SystemeSeeder::seed();
});

/**
 * Le rattachement de l'exercice 2025 qui porte la ligne : tout ou rien, le montant
 * est celui de la ligne d'origine. Aucune écriture comptable n'est générée ici.
 */
function verrouRattacher(TransactionLigne $ligne): Provision
{
    return Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne->id,
        'montant' => max((float) $ligne->debit, (float) $ligne->credit),
        'saisi_par' => auth()->id(),
    ]);
}

describe('TransactionLigne::estRattachee()', function () {
    test('est fausse sans rattachement, vraie dès qu\'il existe, fausse après son retrait', function (int $classe) {
        $ligne = $this->ligneOrigine($classe);

        expect($ligne->estRattachee())->toBeFalse();

        $provision = verrouRattacher($ligne);

        expect(Provision::count())->toBe(1)
            ->and($ligne->fresh()->estRattachee())->toBeTrue();

        // Le retrait du § 6.4 supprime la ligne de provisions : la ligne d'origine est libre.
        $provision->delete();

        expect(Provision::count())->toBe(0)
            ->and($ligne->fresh()->estRattachee())->toBeFalse();
    })->with([
        'charge (classe 6, CCA)' => [6],
        'produit (classe 7, PCA)' => [7],
    ]);

    test('ne vaut que pour la ligne que le rattachement porte', function () {
        $rattachee = $this->ligneOrigine(7);

        // Une ligne sœur, de la même pièce : la sémantique est par ligne. Le gel de la
        // pièce entière (§ 7) est ce que verrouillerPieceNonGelee() compense en interrogeant
        // chacune de ses lignes.
        $compte = Compte::factory()->recette()->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
        $soeur = TransactionLigne::create([
            'transaction_id' => $rattachee->transaction_id,
            'compte_id' => $compte->id,
            'debit' => 0,
            'credit' => 50,
            'montant' => 50,
            'libelle' => 'Ligne sœur',
        ]);

        verrouRattacher($rattachee);

        expect(Provision::count())->toBe(1)
            ->and($rattachee->fresh()->estRattachee())->toBeTrue()
            ->and($soeur->fresh()->estRattachee())->toBeFalse();
    });

    test('reste vraie hors contexte d\'association : le verrou ne s\'ouvre pas faute de tenant', function () {
        $ligne = $this->ligneOrigine(7);
        verrouRattacher($ligne);
        // Avant clear() : après, la ligne ne serait plus trouvée.
        $ligne = $ligne->fresh();

        // Garde anti-test-vide : dans le tenant, le rattachement est visible et verrouille.
        expect(Provision::count())->toBe(1)
            ->and($ligne->estRattachee())->toBeTrue();

        TenantContext::clear();

        // Hors tenant, le scope masque le rattachement (fail-closed) ; le verrou, lui, tient.
        expect(Provision::count())->toBe(0)
            ->and($ligne->estRattachee())->toBeTrue();
    });

    test('delete() supprime physiquement le rattachement et libère la ligne', function () {
        $ligne = $this->ligneOrigine(7);
        $provision = verrouRattacher($ligne);
        $id = $provision->id;

        // Garde anti-test-vide : la ligne de provisions existe, et verrouille.
        expect(DB::table('provisions')->where('id', $id)->exists())->toBeTrue()
            ->and($ligne->fresh()->estRattachee())->toBeTrue();

        $provision->delete();

        // Assertion sur la table elle-même, pas sur le modèle : c'est ce qui tombe si
        // quelqu'un remet SoftDeletes (le modèle masquerait la ligne, la base la garderait).
        expect(DB::table('provisions')->where('id', $id)->exists())->toBeFalse()
            ->and($ligne->fresh()->estRattachee())->toBeFalse();
    });

    test('s\'aligne sur la clé étrangère : la base refuse de supprimer physiquement une ligne rattachée', function () {
        $ligne = $this->ligneOrigine(7);
        verrouRattacher($ligne);

        // Garde anti-test-vide : l'application dit déjà la ligne verrouillée, comme la
        // base va le faire.
        expect($ligne->fresh()->estRattachee())->toBeTrue();

        // C'est ce que fait TransactionService::update() : un forceDelete des lignes.
        // Sans la garde, la base lèverait cette erreur SQL brute à la place d'un refus lisible.
        expect(fn () => TransactionLigne::whereKey($ligne->id)->forceDelete())
            ->toThrow(QueryException::class, 'Integrity constraint violation');

        expect(TransactionLigne::whereKey($ligne->id)->exists())->toBeTrue();
    });

    test('permet la suppression physique de la ligne une fois le rattachement retiré', function () {
        $ligne = $this->ligneOrigine(7);
        verrouRattacher($ligne)->delete();

        expect($ligne->fresh()->estRattachee())->toBeFalse();

        TransactionLigne::whereKey($ligne->id)->forceDelete();

        expect(TransactionLigne::withTrashed()->whereKey($ligne->id)->exists())->toBeFalse();
    });
});

describe('TransactionLigne::estRattacheeSousVerrou()', function () {
    test('répond comme estRattachee() : fausse sans rattachement, vraie avec, fausse après son retrait', function (int $classe) {
        $ligne = $this->ligneOrigine($classe);

        expect($ligne->estRattacheeSousVerrou())->toBeFalse()
            ->and($ligne->estRattachee())->toBeFalse();

        $provision = verrouRattacher($ligne);

        expect($ligne->fresh()->estRattacheeSousVerrou())->toBeTrue()
            ->and($ligne->fresh()->estRattachee())->toBeTrue();

        $provision->delete();

        expect($ligne->fresh()->estRattacheeSousVerrou())->toBeFalse()
            ->and($ligne->fresh()->estRattachee())->toBeFalse();
    })->with([
        'charge (classe 6, CCA)' => [6],
        'produit (classe 7, PCA)' => [7],
    ]);

    test('ne vaut que pour la ligne que le rattachement porte', function () {
        $rattachee = $this->ligneOrigine(7);
        $compte = Compte::factory()->recette()->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
        $soeur = TransactionLigne::create([
            'transaction_id' => $rattachee->transaction_id,
            'compte_id' => $compte->id,
            'debit' => 0,
            'credit' => 50,
            'montant' => 50,
            'libelle' => 'Ligne sœur',
        ]);

        verrouRattacher($rattachee);

        expect($rattachee->fresh()->estRattacheeSousVerrou())->toBeTrue()
            ->and($soeur->fresh()->estRattacheeSousVerrou())->toBeFalse();
    });

    test('reste vraie hors contexte d\'association, comme estRattachee()', function () {
        $ligne = $this->ligneOrigine(7);
        verrouRattacher($ligne);
        $ligne = $ligne->fresh();

        TenantContext::clear();

        expect(Provision::count())->toBe(0)
            ->and($ligne->estRattacheeSousVerrou())->toBeTrue();
    });

    test('lit sous verrou, en lecture directe : « for update » sur provisions, sans EXISTS (MySQL/MariaDB)', function () {
        $ligne = $this->ligneOrigine(7);
        verrouRattacher($ligne);
        $ligne = $ligne->fresh();

        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $ligne->estRattacheeSousVerrou();

        $surProvisions = array_values(array_filter($requetes, fn (string $sql) => str_contains($sql, 'provisions')));
        expect($surProvisions)->toHaveCount(1);

        // Une lecture verrouillante DIRECTE, pas un EXISTS : le FOR UPDATE ne tient pas à une
        // sous-requête, dont rien ne prouve que le moteur la verrouille.
        expect(mb_strtolower($surProvisions[0]))->not->toContain('exists');

        // SQLite n'écrit pas « for update » ; seuls MySQL et MariaDB prouvent le verrou.
        if (DB::getDriverName() !== 'sqlite') {
            expect($surProvisions[0])->toContain('for update');
        }

        // La lecture ordinaire, elle, ne verrouille pas : c'est ce qui distingue les deux méthodes.
        $requetes = [];
        $ligne->estRattachee();

        $ordinaires = array_values(array_filter($requetes, fn (string $sql) => str_contains($sql, 'provisions')));
        expect($ordinaires)->toHaveCount(1)
            ->and($ordinaires[0])->not->toContain('for update');
    });
});

// ── Les gardes du verrou, et ses sites (spec § 7, AC-7) ──────────────────────
//
// Les deux gardes vivent au service. Les premiers tests les éprouvent elles-mêmes, appelées
// directement ; ensuite un test par SITE tue l'appel posé à ce site, et celui-là seulement : retirer
// l'appel de reclasser() ne fait tomber que les tests de reclasser(), alors que casser la garde du
// service fait tomber les siens. Tous les sites refusent avec le même message
// (GelRattachement::MOTIF_PIECE_GELEE), que ce fichier épingle au mot près : les
// tests le comparent à VERROU_MESSAGE. Les helpers de ces tests (VERROU_MESSAGE, verrou…()) vivent
// dans tests/Helpers/RattachementVerrou.php : les tests des sites suivants s'en servent aussi.

describe('GelRattachement::assertLigneNonGelee()', function () {
    test('refuse une ligne rattachée : le message exact, et rien n\'a bougé', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);

        verrouAssertRefuse(
            fn () => app(GelRattachement::class)->assertLigneNonGelee($ligne),
            $ligne,
            $provision,
        );
    })->with('rattachement_classes');

    test('laisse passer une ligne libre, qu\'un rattachement existe ailleurs ou sur sa sœur : la garde regarde cette ligne', function (int $classe) {
        $libre = $this->ligneOrigineEquilibree($classe);
        $soeur = verrouSoeur($classe, $libre);
        verrouRattacherAvecEcritures($this, $soeur);
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree($classe));

        // Garde anti-test-vide : la sœur est bien rattachée, et la ligne libre l'est bien pas.
        expect($soeur->fresh()->estRattachee())->toBeTrue();

        verrouAssertPasse(fn () => app(GelRattachement::class)->assertLigneNonGelee($libre), $libre);
    })->with('rattachement_classes');

    test('refuse une ligne rattachée PUIS supprimée logiquement : lire la ligne sous verrou ne la trouverait plus, et laisserait passer', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $ligne->delete();

        // Prémisses : la ligne est masquée par sa suppression logique, mais toujours en base, et son
        // rattachement tient toujours (la FK RESTRICT interdit la suppression physique).
        expect(TransactionLigne::find($ligne->id))->toBeNull()
            ->and(TransactionLigne::withTrashed()->find($ligne->id))->not->toBeNull();

        // L'instance qu'on a déjà, et une instance relue avec ses suppressions logiques : la garde ne
        // dépend que de l'identifiant.
        verrouAssertRefuse(
            fn () => app(GelRattachement::class)->assertLigneNonGelee($ligne),
            $ligne,
            $provision,
        );
        verrouAssertRefuse(
            fn () => app(GelRattachement::class)->assertLigneNonGelee(TransactionLigne::withTrashed()->findOrFail($ligne->id)),
            $ligne,
            $provision,
        );
    })->with('rattachement_classes');

    test('refuse hors contexte d\'association : le scope tenant (fail-closed) ne rend pas la ligne libre', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        verrouRattacherAvecEcritures($this, $ligne);
        $libre = $this->ligneOrigineEquilibree($classe);
        $ligne = $ligne->fresh();
        $association = TenantContext::current();

        TenantContext::clear();

        // Garde anti-test-vide : hors contexte, ni le rattachement ni la ligne ne se lisent sous leur scope.
        expect(Provision::count())->toBe(0)
            ->and(TransactionLigne::find($ligne->id))->toBeNull();

        $service = app(GelRattachement::class);

        expect(verrouMessageDuRefus(fn () => $service->assertLigneNonGelee($ligne)))->toBe(VERROU_MESSAGE);

        // Et la garde ne refuse pas pour autant tout ce qu'elle ne voit pas : une ligne libre passe.
        expect(verrouMessageDuRefus(fn () => $service->assertLigneNonGelee($libre)))->toBeNull();

        TenantContext::boot($association);
    })->with('rattachement_classes');

    // Une ligne d'écriture de rattachement ne porte jamais de rattachement : la garde la refuse parce que sa
    // PIÈCE en est une (provision_id), sans quoi tout geste sur une ligne la laisserait passer.
    test('refuse une ligne d\'écriture de rattachement, dotation ou extourne : son propre message, et rien n\'a bougé', function (int $classe, string $ecriture) {
        ['ligne' => $ligne, 'provision' => $provision, 'ecriture' => $ligneDeLEcriture] = verrouEcritureDeRattachement($this, $classe, $ecriture);

        // Garde anti-test-vide : la ligne visée ne porte aucun rattachement, c'est sa pièce qui en est une.
        expect($ligneDeLEcriture->estRattachee())->toBeFalse()
            ->and((int) $ligneDeLEcriture->transaction->provision_id)->toBe((int) $provision->id);

        verrouAssertRefuse(
            fn () => app(GelRattachement::class)->assertLigneNonGelee($ligneDeLEcriture),
            $ligne,
            $provision,
            VERROU_MESSAGE_ECRITURE,
        );
    })->with('rattachement_classes')->with([
        'la dotation' => ['normale'],
        'l\'extourne' => ['extourne'],
    ]);

    test('refuse une ligne d\'écriture de rattachement supprimée logiquement, ou hors contexte d\'association : ni la suppression logique ni le scope tenant ne la rendent libre', function (string $cas) {
        ['ligne' => $ligne, 'provision' => $provision, 'ecriture' => $ligneDeLEcriture] = verrouEcritureDeRattachement($this, 6, 'normale');
        $libre = $this->ligneOrigineEquilibree(6);
        $association = TenantContext::current();

        if ($cas === 'ligne supprimée') {
            $ligneDeLEcriture->delete();
        } elseif ($cas === 'pièce supprimée') {
            $ligneDeLEcriture->transaction->delete();
        } else {
            $ligneDeLEcriture = $ligneDeLEcriture->fresh();
            TenantContext::clear();
        }

        // Prémisse : la ligne, sa pièce ou les deux sont masquées par leur scope, et la base les garde.
        expect(TransactionLigne::withoutGlobalScopes()->whereKey($ligneDeLEcriture->id)->exists())->toBeTrue()
            ->and(Transaction::query()->whereKey($ligneDeLEcriture->transaction_id)->exists())->toBe($cas === 'ligne supprimée');

        $service = app(GelRattachement::class);

        expect(verrouMessageDuRefus(fn () => $service->assertLigneNonGelee($ligneDeLEcriture)))->toBe(VERROU_MESSAGE_ECRITURE);

        // Et la garde ne refuse pas pour autant tout ce qu'elle ne voit pas : une ligne libre passe.
        expect(verrouMessageDuRefus(fn () => $service->assertLigneNonGelee($libre)))->toBeNull();

        if ($cas === 'hors contexte') {
            TenantContext::boot($association);
        }

        expect(verrouEmpreinte($ligne, $provision)['provision'])->not->toBeEmpty();
    })->with([
        'ligne supprimée' => ['ligne supprimée'],
        'pièce supprimée' => ['pièce supprimée'],
        'hors contexte' => ['hors contexte'],
    ]);

    test('verrouille la ligne, tous scopes levés, puis lit les rattachements : « for update » dans cet ordre, sans EXISTS (MariaDB)', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $service = app(GelRattachement::class);

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => DB::transaction(fn () => expect(fn () => $service->assertLigneNonGelee($ligne))
                ->toThrow(RuntimeException::class, VERROU_MESSAGE))
        );

        $indices = [
            'ligne' => rattachementIndiceDeLaPremiere($requetes, 'transaction_lignes'),
            'rattachements' => rattachementIndiceDeLaPremiere($requetes, 'provisions'),
        ];

        // Dans l'ordre : la ligne d'abord (l'ordre de rattacher(), dont c'est une sous-suite), puis les rattachements.
        expect($indices['ligne'])->toBeLessThan($indices['rattachements']);

        // Une lecture de la ligne par son identifiant, et une seule lecture des rattachements, directe : pas d'EXISTS.
        expect(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'provisions')))->toHaveCount(1)
            ->and(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'transaction_lignes')))->toHaveCount(1)
            ->and(mb_strtolower($requetes[$indices['rattachements']]->sql))->not->toContain('exists');

        // Aucune des deux ne porte de scope.
        foreach ($indices as $i) {
            verrouAssertSansScope($requetes[$i]);
        }

        // La lecture des rattachements vise cette ligne, par son identifiant.
        expect($requetes[$indices['rattachements']]->bindings)->toContain((int) $ligne->id)
            ->and($requetes[$indices['ligne']]->bindings)->toContain((int) $ligne->id);

        // SQLite n'écrit pas « for update » : seuls MySQL et MariaDB prouvent que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($indices as $i) {
                expect($requetes[$i]->sql)->toContain('for update');
            }
        }

        // Garde anti-test-vide : le rattachement lu existe.
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeTrue();
    });
});

describe('GelRattachement::verrouillerPieceNonGelee()', function () {
    test('refuse une pièce dont une ligne est rattachée, même si ce n\'est pas la première : le message exact, et rien n\'a bougé', function (int $classe) {
        ['libre' => $libre, 'soeur' => $soeur, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, $classe);

        // Garde anti-test-vide : c'est bien la dernière ligne qui est rattachée, pas la première.
        expect($libre->estRattachee())->toBeFalse()
            ->and($soeur->estRattachee())->toBeTrue()
            ->and((int) $soeur->id)->toBeGreaterThan((int) $libre->id);

        verrouAssertRefuse(
            fn () => app(GelRattachement::class)->verrouillerPieceNonGelee($piece),
            $soeur,
            $provision,
        );
    })->with('rattachement_classes');

    test('laisse passer une pièce libre, alors qu\'un rattachement existe sur une autre pièce', function (int $classe) {
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree($classe));

        $libre = $this->ligneOrigineEquilibree($classe);
        $piece = Transaction::findOrFail($libre->transaction_id);

        verrouAssertPasse(fn () => app(GelRattachement::class)->verrouillerPieceNonGelee($piece), $libre);
    })->with('rattachement_classes');

    test('refuse quand la ligne rattachée, la pièce, ou les deux, sont supprimées logiquement : les suppressions logiques ne dégèlent rien', function (bool $ligneSupprimee, bool $pieceSupprimee) {
        ['soeur' => $soeur, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);

        if ($ligneSupprimee) {
            $soeur->delete();
        }
        if ($pieceSupprimee) {
            $piece->delete();
        }

        // Prémisses : les suppressions logiques masquent bien ce qu'elles doivent, et la base le garde.
        expect(TransactionLigne::find($soeur->id) === null)->toBe($ligneSupprimee)
            ->and(Transaction::find($piece->id) === null)->toBe($pieceSupprimee)
            ->and(DB::table('provisions')->where('id', $provision->id)->exists())->toBeTrue();

        verrouAssertRefuse(
            fn () => app(GelRattachement::class)->verrouillerPieceNonGelee($piece),
            $soeur,
            $provision,
        );
    })->with([
        'ligne supprimée' => [true, false],
        'pièce supprimée' => [false, true],
        'ligne et pièce supprimées' => [true, true],
    ]);

    test('refuse hors contexte d\'association : le scope tenant (fail-closed) ne rend pas la pièce libre', function () {
        ['soeur' => $soeur, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);
        $libre = Transaction::findOrFail($this->ligneOrigineEquilibree(7)->transaction_id);
        $association = TenantContext::current();

        TenantContext::clear();

        // Garde anti-test-vide : hors contexte, ni le rattachement ni les lignes de la pièce ne se lisent sous leur scope.
        expect(Provision::count())->toBe(0)
            ->and(TransactionLigne::where('transaction_id', $piece->id)->count())->toBe(0);

        $service = app(GelRattachement::class);

        expect(verrouMessageDuRefus(fn () => $service->verrouillerPieceNonGelee($piece)))->toBe(VERROU_MESSAGE);

        // Et la garde ne refuse pas pour autant tout ce qu'elle ne voit pas : une pièce libre passe.
        expect(verrouMessageDuRefus(fn () => $service->verrouillerPieceNonGelee($libre)))->toBeNull();

        TenantContext::boot($association);
    });

    test('verrouille les lots 401/411, puis la pièce, puis ses lignes, puis lit les rattachements en UNE lecture : « for update » dans cet ordre (MariaDB)', function () {
        ['soeur' => $soeur, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);
        $service = app(GelRattachement::class);

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => DB::transaction(fn () => expect(fn () => $service->verrouillerPieceNonGelee($piece))
                ->toThrow(RuntimeException::class, VERROU_MESSAGE))
        );

        // Chaque verrou une fois et une seule, dans l'ordre de TransactionService::update() prolongé :
        // les lots, la pièce, les lignes, les rattachements. Le refus est la dernière requête : rien après.
        $indices = verrouIndicesDesVerrous($requetes);
        expect(array_keys($indices))->toBe(['liste des lots', 'lots', 'pièce', 'lignes', 'rattachements'])
            ->and(array_values($indices))->toBe(collect($indices)->sort()->values()->all())
            ->and(array_key_last($requetes))->toBe($indices['rattachements']);

        // Une lecture directe des rattachements : pas d'EXISTS, pas une lecture par ligne.
        expect(mb_strtolower($requetes[$indices['rattachements']]->sql))->not->toContain('exists');

        // Les lignes se verrouillent dans l'ordre de leur identifiant : deux appelants qui verrouillent
        // les mêmes lignes ne s'attendent pas l'un l'autre en sens inverse.
        expect(mb_strtolower($requetes[$indices['lignes']]->sql))->toContain('order by');

        // Les lots désignent le poste tiers de la pièce (la contrepartie 411) ; la pièce, ses lignes et
        // les rattachements ne portent AUCUN scope : seul le préfixe canonique se lit sous le scope tenant.
        $contrepartie = (int) DB::table('transaction_lignes')->where('transaction_id', $piece->id)->orderBy('id')->skip(1)->value('id');
        expect($requetes[$indices['lots']]->bindings)->toContain($contrepartie);
        foreach (['pièce', 'lignes', 'rattachements'] as $nom) {
            verrouAssertSansScope($requetes[$indices[$nom]]);
        }

        // Les rattachements sont lus pour TOUTES les lignes de la pièce, la sœur rattachée comprise.
        $lignesDeLaPiece = DB::table('transaction_lignes')->where('transaction_id', $piece->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        expect($lignesDeLaPiece)->toContain((int) $soeur->id)->and(count($lignesDeLaPiece))->toBe(3);
        foreach ($lignesDeLaPiece as $ligneId) {
            expect($requetes[$indices['rattachements']]->bindings)->toContain($ligneId);
        }

        // SQLite n'écrit pas « for update » : seuls MySQL et MariaDB prouvent que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['lots', 'pièce', 'lignes', 'rattachements'] as $nom) {
                expect($requetes[$indices[$nom]]->sql)->toContain('for update');
            }
            // La liste des lots n'est qu'une lecture : c'est ce qui la distingue des verrous.
            expect($requetes[$indices['liste des lots']]->sql)->not->toContain('for update');
        }

        // Garde anti-test-vide : le rattachement lu existe.
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeTrue();
    });

    test('rend la pièce relue sous verrou, et lève une ModelNotFoundException quand elle n\'existe plus', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $piece = Transaction::findOrFail($ligne->transaction_id);
        $service = app(GelRattachement::class);

        // L'instance de l'appelant est périmée : la pièce rendue est celle de la base, sous verrou.
        DB::table('transactions')->where('id', $piece->id)->update(['libelle' => 'Libellé de la base']);
        expect($piece->libelle)->not->toBe('Libellé de la base');

        $rendue = $service->verrouillerPieceNonGelee($piece);

        expect($rendue)->toBeInstanceOf(Transaction::class)
            ->and((int) $rendue->id)->toBe((int) $piece->id)
            ->and($rendue->libelle)->toBe('Libellé de la base')
            ->and($rendue)->not->toBe($piece);

        // Une pièce qui n'existe plus (supprimée physiquement) ne se rend pas : un refus, pas un passage.
        DB::table('transaction_lignes')->where('transaction_id', $piece->id)->delete();
        DB::table('transactions')->where('id', $piece->id)->delete();

        expect(fn () => $service->verrouillerPieceNonGelee($piece))->toThrow(ModelNotFoundException::class);
    });

    test('verrouille chaque lot canonique de la pièce, une fois, dans l\'ordre croissant de la ligne canonique : la ligne de tiers elle-même ou son parent', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $piece = Transaction::findOrFail($ligne->transaction_id);
        $enfant = TransactionLigne::where('transaction_id', $piece->id)->orderBy('id')->skip(1)->firstOrFail();

        // Un poste seul, puis un poste parent créé APRÈS le fils : le premier fils de la liste (par
        // identifiant) pointe vers un parent d'identifiant supérieur. Les lots se prennent dans l'ordre de
        // la ligne canonique, pas dans celui de la liste des lignes de tiers.
        $seul = TransactionLigne::create([
            'transaction_id' => $piece->id,
            'compte_id' => $enfant->compte_id,
            'debit' => 5,
            'credit' => 0,
            'montant' => 0,
            'libelle' => 'Poste seul',
        ]);
        $parent = TransactionLigne::create([
            'transaction_id' => $piece->id,
            'compte_id' => $enfant->compte_id,
            'debit' => 10,
            'credit' => 0,
            'montant' => 0,
            'libelle' => 'Poste parent',
        ]);
        $enfant->forceFill(['poste_tiers_parent_id' => $parent->id])->save();

        // Garde anti-test-vide : le fils précède son parent par l'identifiant, avec le poste seul entre les deux.
        expect((int) $enfant->id)->toBeLessThan((int) $seul->id)
            ->and((int) $seul->id)->toBeLessThan((int) $parent->id);

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => DB::transaction(fn () => app(VerrouLotsCanoniques::class)->verrouillerLotsDeLaPiece((int) $piece->id))
        );

        $lots = array_values(array_filter(
            $requetes,
            fn (object $r): bool => preg_match('/or\s+[`"]poste_tiers_parent_id[`"]\s*=\s*\?/i', $r->sql) === 1
        ));

        // Le fils se ramène à son parent : deux lots, pas trois, par ordre croissant de ligne canonique (le
        // poste seul, puis le parent), alors que la liste commence par le fils. Les deux premiers paramètres
        // de chaque verrou sont la ligne canonique (la ligne, puis ses fils) : le troisième est l'association,
        // que rien n'empêche de valoir l'identifiant d'une ligne.
        expect($lots)->toHaveCount(2)
            ->and(array_slice($lots[0]->bindings, 0, 2))->toBe([(int) $seul->id, (int) $seul->id])
            ->and(array_slice($lots[1]->bindings, 0, 2))->toBe([(int) $parent->id, (int) $parent->id]);

        // Un verrou par lot, tous les lots (supprimés logiquement compris) : aucun filtre de suppression sur le verrou.
        expect($lots[0]->sql)->not->toContain('deleted_at');

        if (DB::getDriverName() !== 'sqlite') {
            expect($lots[0]->sql)->toContain('for update')
                ->and($lots[1]->sql)->toContain('for update');
        }
    });
});

describe('GelRattachement::motifDeGel()', function () {
    // La lecture SANS verrou qui prévient l'écran : même question que les gardes, mêmes réponses, sans rien verrouiller.
    // Les gardes, elles, décident sous verrou ; ces tests n'éprouvent que ce que l'écran en lit.
    test('rend le motif du gel pour une pièce dont une ligne est rattachée, même si ce n\'est pas la première, et null pour une pièce libre', function (int $classe) {
        ['soeur' => $soeur, 'libre' => $libre, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, $classe);
        $autre = $this->ligneOrigineEquilibree($classe);
        $service = app(GelRattachement::class);

        expect($service->motifDeGel((int) $piece->id))->toBe(GelRattachement::MOTIF_PIECE_GELEE)
            ->and($service->motifDeGel((int) $autre->transaction_id))->toBeNull();

        // Garde anti-test-vide : la ligne libre de la pièce gelée n'est pas elle-même rattachée.
        expect($libre->estRattachee())->toBeFalse()
            ->and($soeur->estRattachee())->toBeTrue();
    })->with('rattachement_classes');

    test('rend le motif propre à l\'écriture de rattachement, dotation ou extourne générée', function (string $ecriture) {
        ['piece' => $piece] = verrouEcritureDeRattachement($this, 7, $ecriture);

        expect(app(GelRattachement::class)->motifDeGel((int) $piece->id))->toBe(GelRattachement::MOTIF_ECRITURE_DE_RATTACHEMENT);
    })->with([
        'la dotation' => ['normale'],
        'l\'extourne' => ['extourne'],
    ]);

    test('ne rend plus de motif une fois le rattachement retiré', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $service = app(GelRattachement::class);

        expect($service->motifDeGel((int) $ligne->transaction_id))->toBe(GelRattachement::MOTIF_PIECE_GELEE);

        app(RattachementExerciceService::class)->retirer($provision);

        expect($service->motifDeGel((int) $ligne->transaction_id))->toBeNull();
    });

    test('rend le motif quand la ligne rattachée, la pièce, ou les deux, sont supprimées logiquement, ou hors du contexte d\'association : aucun scope ne dégèle', function (string $cas) {
        ['soeur' => $soeur, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);
        $service = app(GelRattachement::class);
        $association = TenantContext::current();

        match ($cas) {
            'ligne supprimée' => $soeur->delete(),
            'pièce supprimée' => $piece->delete(),
            'hors contexte' => TenantContext::clear(),
        };

        expect($service->motifDeGel((int) $piece->id))->toBe(GelRattachement::MOTIF_PIECE_GELEE);

        TenantContext::boot($association);
    })->with([
        'ligne supprimée' => ['ligne supprimée'],
        'pièce supprimée' => ['pièce supprimée'],
        'hors contexte d\'association' => ['hors contexte'],
    ]);

    // L'écran se fie à cette lecture : elle ne doit pas diverger des gardes qui, elles, décident. Sur chaque forme de
    // pièce, le motif lu vaut EXACTEMENT le message que verrouillerPieceNonGelee() lève (ou null si elle passe), et les
    // lignes que l'écran dit gelées sont EXACTEMENT celles sur lesquelles assertLigneNonGelee() lève.
    test('motifDeGel() et etatDeGel() s\'accordent avec les gardes sur chaque forme de pièce', function (string $cas, string $attendu) {
        $piece = match ($cas) {
            'pièce libre' => Transaction::findOrFail($this->ligneOrigineEquilibree(7)->transaction_id),
            'ligne rattachée', 'hors contexte d\'association' => (function () {
                $ligne = $this->ligneOrigineEquilibree(7);
                verrouRattacherAvecEcritures($this, $ligne);

                return Transaction::findOrFail($ligne->transaction_id);
            })(),
            'sœur d\'une ligne rattachée' => verrouPieceDontLaSoeurEstRattachee($this, 7)['piece'],
            'ligne rattachée supprimée logiquement' => (function () {
                $ligne = $this->ligneOrigineEquilibree(7);
                verrouRattacherAvecEcritures($this, $ligne);
                $ligne->delete();

                return Transaction::findOrFail($ligne->transaction_id);
            })(),
            'pièce supprimée logiquement' => (function () {
                $ligne = $this->ligneOrigineEquilibree(7);
                verrouRattacherAvecEcritures($this, $ligne);
                $piece = Transaction::findOrFail($ligne->transaction_id);
                $piece->delete();

                return $piece;
            })(),
            'dotation', 'dotation hors contexte d\'association' => verrouEcritureDeRattachement($this, 7, 'normale')['piece'],
            'extourne' => verrouEcritureDeRattachement($this, 7, 'extourne')['piece'],
            'dotation supprimée logiquement' => (function () {
                $piece = verrouEcritureDeRattachement($this, 7, 'normale')['piece'];
                $piece->delete();

                return $piece;
            })(),
        };
        $association = TenantContext::current();

        if (in_array($cas, ['hors contexte d\'association', 'dotation hors contexte d\'association'], true)) {
            TenantContext::clear();
        }

        $gel = app(GelRattachement::class);
        $attenduMotif = match ($attendu) {
            'aucun' => null,
            'pièce' => GelRattachement::MOTIF_PIECE_GELEE,
            'écriture' => GelRattachement::MOTIF_ECRITURE_DE_RATTACHEMENT,
        };

        // La pièce : le motif lu est le refus de la garde de pièce.
        $refus = verrouMessageDuRefus(fn () => DB::transaction(fn () => $gel->verrouillerPieceNonGelee($piece)));
        expect($refus)->toBe($attenduMotif)
            ->and($gel->motifDeGel((int) $piece->id))->toBe($refus);

        // Les lignes (supprimées logiquement comprises) : celles que l'écran dit gelées sont celles que la garde de ligne refuse.
        $ligneIds = DB::table('transaction_lignes')->where('transaction_id', $piece->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $refusees = array_values(array_filter($ligneIds, fn (int $id): bool => verrouMessageDuRefus(
            fn () => DB::transaction(fn () => $gel->assertLigneNonGelee(TransactionLigne::withoutGlobalScopes()->findOrFail($id)))
        ) !== null));

        // Garde anti-test-vide : la pièce a des lignes, et la garde de ligne en refuse exactement quand la pièce l'est.
        expect($ligneIds)->not->toBeEmpty()
            ->and($gel->etatDeGel((int) $piece->id)['lignes'])->toBe($refusees)
            ->and($refusees !== [])->toBe($attendu !== 'aucun');

        TenantContext::boot($association);
    })->with([
        'pièce libre' => ['pièce libre', 'aucun'],
        'ligne rattachée' => ['ligne rattachée', 'pièce'],
        'sœur d\'une ligne rattachée' => ['sœur d\'une ligne rattachée', 'pièce'],
        'ligne rattachée supprimée logiquement' => ['ligne rattachée supprimée logiquement', 'pièce'],
        'pièce supprimée logiquement' => ['pièce supprimée logiquement', 'pièce'],
        'dotation' => ['dotation', 'écriture'],
        'extourne' => ['extourne', 'écriture'],
        'hors contexte d\'association' => ['hors contexte d\'association', 'pièce'],
        'dotation supprimée logiquement' => ['dotation supprimée logiquement', 'écriture'],
        'dotation hors contexte d\'association' => ['dotation hors contexte d\'association', 'écriture'],
    ]);

    test('ne verrouille rien et n\'ouvre aucune transaction : une lecture simple, qui peut être périmée', function () {
        ['piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);
        $service = app(GelRattachement::class);

        $requetes = rattachementRequetesDeLaTransaction(fn () => $service->motifDeGel((int) $piece->id));

        // Aucune requête DANS une transaction qu'elle aurait ouverte : elle n'en ouvre pas.
        expect($requetes)->toBe([]);

        // Et aucune ne verrouille, même hors transaction (MariaDB écrirait « for update »).
        $sql = [];
        DB::listen(function ($requete) use (&$sql): void {
            $sql[] = $requete->sql;
        });
        $service->motifDeGel((int) $piece->id);

        expect($sql)->not->toBeEmpty();
        foreach ($sql as $requete) {
            expect($requete)->not->toContain('for update');
        }
    });
});

describe('GelRattachement : le graphe d\'injection', function () {
    // Le gel est reçu par les gestes qu'il protège : il ne doit atteindre, par son constructeur, aucun
    // d'eux, ni ce qui crée ou défait un rattachement. Sans cela, un cycle d'injection est possible, et
    // l'écriture comptable entre dans la chaîne de construction d'un geste qui ne fait que se protéger.
    test('GelRattachement ne dépend que d\'ExerciceService et de VerrouLotsCanoniques : ni ProvisionPDService, ni EcritureGenerator, ni aucun geste protégé', function () {
        $dependances = verrouDependancesDuConstructeur(GelRattachement::class);

        // Garde anti-test-vide : le parcours atteint bien ses deux dépendances, et elles seules.
        expect($dependances)->toEqualCanonicalizing([ExerciceService::class, VerrouLotsCanoniques::class]);

        foreach ([
            ProvisionPDService::class,
            EcritureGenerator::class,
            RattachementExerciceService::class,
            TransactionService::class,
            TransactionExtourneService::class,
            ReclassementLigneService::class,
        ] as $interdite) {
            expect($dependances)->not->toContain($interdite);
        }
    });

    test('chaque geste protégé reçoit le gel, et n\'atteint ni le service de rattachement ni ProvisionPDService : pas de cycle d\'injection', function (string $geste) {
        $dependances = verrouDependancesDuConstructeur($geste);

        expect($dependances)->toContain(GelRattachement::class)
            ->and($dependances)->not->toContain(RattachementExerciceService::class)
            ->and($dependances)->not->toContain(ProvisionPDService::class);

        // Il se résout.
        expect(app($geste))->toBeInstanceOf($geste);
    })->with([
        'TransactionService' => [TransactionService::class],
        'TransactionExtourneService' => [TransactionExtourneService::class],
        'ReclassementLigneService' => [ReclassementLigneService::class],
    ]);

    test('RattachementExerciceService n\'atteint jamais TransactionExtourneService par son constructeur : pas de cycle d\'injection', function () {
        $dependances = verrouDependancesDuConstructeur(RattachementExerciceService::class);

        // Garde anti-test-vide : le parcours atteint bien des dépendances.
        expect($dependances)->not->toBeEmpty()
            ->and($dependances)->not->toContain(TransactionExtourneService::class);

        // Il se résout.
        expect(app(RattachementExerciceService::class))->toBeInstanceOf(RattachementExerciceService::class);
    });
});
