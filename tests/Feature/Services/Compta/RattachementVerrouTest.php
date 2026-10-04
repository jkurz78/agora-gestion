<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Models\Compte;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Verrou d'une ligne rattachée à l'exercice (CCA/PCA)
|--------------------------------------------------------------------------
|
| Une ligne portée par un rattachement gèle sa pièce entière (spec § 7).
| TransactionLigne::estRattachee() est la source de vérité de ce verrou, lue sous verrou par
| estRattacheeSousVerrou() : la garde vit au service, dans assertLigneNonGelee() (un geste qui
| écrit sur une ligne) et assertPieceNonGelee() (un geste qui écrit sur la pièce entière), que
| les sites appellent (Task 7 : reclassement ; Tasks 8 et 9 : les autres). Ce fichier éprouve
| ces deux gardes, puis un test par site, à mesure qu'ils sont posés.
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
        // pièce entière (§ 7) est ce que assertPieceNonGelee() compense en interrogeant
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
// (RattachementExerciceService::MOTIF_PIECE_GELEE), que ce fichier épingle au mot près : les
// tests le comparent à VERROU_MESSAGE. Les helpers de ces tests (VERROU_MESSAGE, verrou…()) vivent
// dans tests/Helpers/RattachementVerrou.php : les tests des sites suivants s'en servent aussi.

describe('RattachementExerciceService::assertLigneNonGelee()', function () {
    test('refuse une ligne rattachée : le message exact, et rien n\'a bougé', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);

        verrouAssertRefuse(
            fn () => app(RattachementExerciceService::class)->assertLigneNonGelee($ligne),
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

        verrouAssertPasse(fn () => app(RattachementExerciceService::class)->assertLigneNonGelee($libre), $libre);
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
            fn () => app(RattachementExerciceService::class)->assertLigneNonGelee($ligne),
            $ligne,
            $provision,
        );
        verrouAssertRefuse(
            fn () => app(RattachementExerciceService::class)->assertLigneNonGelee(TransactionLigne::withTrashed()->findOrFail($ligne->id)),
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

        $service = app(RattachementExerciceService::class);

        expect(verrouMessageDuRefus(fn () => $service->assertLigneNonGelee($ligne)))->toBe(VERROU_MESSAGE);

        // Et la garde ne refuse pas pour autant tout ce qu'elle ne voit pas : une ligne libre passe.
        expect(verrouMessageDuRefus(fn () => $service->assertLigneNonGelee($libre)))->toBeNull();

        TenantContext::boot($association);
    })->with('rattachement_classes');

    test('verrouille la ligne, tous scopes levés, puis lit les rattachements : « for update » dans cet ordre, sans EXISTS (MariaDB)', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $service = app(RattachementExerciceService::class);

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

describe('RattachementExerciceService::assertPieceNonGelee()', function () {
    test('refuse une pièce dont une ligne est rattachée, même si ce n\'est pas la première : le message exact, et rien n\'a bougé', function (int $classe) {
        ['libre' => $libre, 'soeur' => $soeur, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, $classe);

        // Garde anti-test-vide : c'est bien la dernière ligne qui est rattachée, pas la première.
        expect($libre->estRattachee())->toBeFalse()
            ->and($soeur->estRattachee())->toBeTrue()
            ->and((int) $soeur->id)->toBeGreaterThan((int) $libre->id);

        verrouAssertRefuse(
            fn () => app(RattachementExerciceService::class)->assertPieceNonGelee($piece),
            $soeur,
            $provision,
        );
    })->with('rattachement_classes');

    test('laisse passer une pièce libre, alors qu\'un rattachement existe sur une autre pièce', function (int $classe) {
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree($classe));

        $libre = $this->ligneOrigineEquilibree($classe);
        $piece = Transaction::findOrFail($libre->transaction_id);

        verrouAssertPasse(fn () => app(RattachementExerciceService::class)->assertPieceNonGelee($piece), $libre);
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
            fn () => app(RattachementExerciceService::class)->assertPieceNonGelee($piece),
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

        $service = app(RattachementExerciceService::class);

        expect(verrouMessageDuRefus(fn () => $service->assertPieceNonGelee($piece)))->toBe(VERROU_MESSAGE);

        // Et la garde ne refuse pas pour autant tout ce qu'elle ne voit pas : une pièce libre passe.
        expect(verrouMessageDuRefus(fn () => $service->assertPieceNonGelee($libre)))->toBeNull();

        TenantContext::boot($association);
    });

    test('verrouille la pièce, puis ses lignes, tous scopes levés, puis lit les rattachements en UNE lecture : « for update » dans cet ordre (MariaDB)', function () {
        ['soeur' => $soeur, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);
        $service = app(RattachementExerciceService::class);

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => DB::transaction(fn () => expect(fn () => $service->assertPieceNonGelee($piece))
                ->toThrow(RuntimeException::class, VERROU_MESSAGE))
        );

        $indices = [
            'pièce' => rattachementIndiceDeLaPremiere($requetes, 'transactions'),
            'lignes' => rattachementIndiceDeLaPremiere($requetes, 'transaction_lignes'),
            'rattachements' => rattachementIndiceDeLaPremiere($requetes, 'provisions'),
        ];

        // Strictement croissants : chaque verrou est pris après le précédent.
        expect(array_values($indices))->toBe(collect($indices)->sort()->values()->all())
            ->and(count(array_unique($indices)))->toBe(3);

        // Une lecture par table, toutes directes : pas d'EXISTS, pas une lecture par ligne.
        foreach (['transactions', 'transaction_lignes', 'provisions'] as $table) {
            expect(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, $table)))->toHaveCount(1);
        }
        expect(mb_strtolower($requetes[$indices['rattachements']]->sql))->not->toContain('exists');

        // Les lignes se verrouillent dans l'ordre de leur identifiant : deux appelants qui verrouillent
        // les mêmes lignes ne s'attendent pas l'un l'autre en sens inverse.
        expect(mb_strtolower($requetes[$indices['lignes']]->sql))->toContain('order by');

        // Aucune des trois ne porte de scope.
        foreach ($indices as $i) {
            verrouAssertSansScope($requetes[$i]);
        }

        // Les rattachements sont lus pour TOUTES les lignes de la pièce, la sœur rattachée comprise.
        $lignesDeLaPiece = DB::table('transaction_lignes')->where('transaction_id', $piece->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        expect($lignesDeLaPiece)->toContain((int) $soeur->id)->and(count($lignesDeLaPiece))->toBe(3);
        foreach ($lignesDeLaPiece as $ligneId) {
            expect($requetes[$indices['rattachements']]->bindings)->toContain($ligneId);
        }

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

describe('ReclassementLigneService::reclasser() : une ligne rattachée n\'est pas reclassable', function () {
    test('refuse de reclasser une ligne rattachée : le message exact, et la ligne comme les deux écritures n\'ont pas bougé', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $autreCompte = verrouAutreCompte($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);

        // Garde anti-test-vide : la ligne est rattachée, et le compte visé diffère bien de l'actuel.
        expect($ligne->fresh()->estRattachee())->toBeTrue()
            ->and((int) $autreCompte->id)->not->toBe((int) $ligne->compte_id);

        verrouAssertRefuse(fn () => verrouReclasser($this, $ligne, $autreCompte), $ligne, $provision);

        // La ligne n'a pas bougé : compte, opération, séance, marque de reclassement.
        $apres = $ligne->fresh();
        expect((int) $apres->compte_id)->toBe((int) $ligne->compte_id)
            ->and($apres->operation_id)->toBeNull()
            ->and($apres->reclassee_at)->toBeNull()
            ->and($apres->reclassement_compte_origine_id)->toBeNull();
    })->with('rattachement_classes');

    test('refuse de reclasser une ligne rattachée puis supprimée logiquement : la garde ne la perd pas de vue', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $autreCompte = verrouAutreCompte($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $ligne->delete();

        // Prémisse : supprimée logiquement, la ligne n'est plus trouvée par une lecture ordinaire.
        expect(TransactionLigne::find($ligne->id))->toBeNull();

        verrouAssertRefuse(fn () => verrouReclasser($this, $ligne, $autreCompte), $ligne, $provision);
    })->with('rattachement_classes');

    test('reclasse une ligne non rattachée de la même forme : la garde ne bloque pas ce qu\'elle n\'a pas à bloquer', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $autreCompte = verrouAutreCompte($classe);

        // Un rattachement existe ailleurs : la garde regarde cette ligne, pas « un rattachement ».
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree($classe));

        verrouAssertPasse(fn () => verrouReclasser($this, $ligne, $autreCompte), $ligne);

        $apres = $ligne->fresh();
        expect((int) $apres->compte_id)->toBe((int) $autreCompte->id)
            ->and($apres->reclassee_at)->not->toBeNull();
    })->with('rattachement_classes');

    test('reclasse une ligne dont le rattachement a été retiré : la sortie annoncée par le refus fonctionne', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $autreCompte = verrouAutreCompte($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);

        // Rattachée, elle est refusée (garde anti-test-vide : c'est bien le retrait qui la libère).
        verrouAssertRefuse(fn () => verrouReclasser($this, $ligne, $autreCompte), $ligne, $provision);

        app(RattachementExerciceService::class)->retirer($provision);

        verrouAssertPasse(fn () => verrouReclasser($this, $ligne->fresh(), $autreCompte), $ligne);

        expect((int) $ligne->fresh()->compte_id)->toBe((int) $autreCompte->id);
    })->with('rattachement_classes');

    test('lit les rattachements par une lecture verrouillante directe (« for update », sans EXISTS), après le verrou de la ligne, elle-même après celui de l\'exercice', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $autreCompte = verrouAutreCompte(7);
        verrouRattacherAvecEcritures($this, $ligne);
        $ligne = $ligne->fresh();

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => expect(fn () => verrouReclasser($this, $ligne, $autreCompte))
                ->toThrow(RuntimeException::class, VERROU_MESSAGE)
        );

        // La lecture de la ligne par son identifiant : c'est la seule de cette forme dans un refus
        // (les autres lectures de transaction_lignes passent par la pièce ou après l'écriture).
        $indiceLigne = null;
        foreach ($requetes as $i => $requete) {
            if (preg_match('/from\s+[`"]transaction_lignes[`"]\s+where\s+[`"]transaction_lignes[`"]\.[`"]id[`"]\s*=\s*\?/i', $requete->sql) === 1) {
                $indiceLigne = $i;
                break;
            }
        }
        expect($indiceLigne)->toBeInt();

        $indices = [
            'exercice' => rattachementIndiceDeLaPremiere($requetes, 'exercices', 2025),
            'ligne' => $indiceLigne,
            'rattachements' => rattachementIndiceDeLaPremiere($requetes, 'provisions'),
        ];

        // Strictement croissants : chaque verrou est pris après le précédent.
        expect(array_values($indices))->toBe(collect($indices)->sort()->values()->all())
            ->and(count(array_unique($indices)))->toBe(3);

        // Une lecture verrouillante DIRECTE, pas un EXISTS ; une seule lecture des rattachements.
        expect(mb_strtolower($requetes[$indices['rattachements']]->sql))->not->toContain('exists')
            ->and(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'provisions')))->toHaveCount(1);

        // SQLite n'écrit pas « for update » : seul MySQL/MariaDB prouve que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($indices as $i) {
                expect($requetes[$i]->sql)->toContain('for update');
            }
        }
    });
});

describe('TransactionForm::removeLigne() : retour immédiat sur une ligne rattachée', function () {
    // removeLigne() ne touche jamais la base : la suppression réelle a lieu à l'enregistrement
    // (TransactionService::update(), que la Task 8 gardera). Ces tests lisent donc l'ÉTAT DU
    // COMPOSANT : une assertion sur la base passerait même sans garde.

    test('refuse de retirer du formulaire une ligne rattachée : elle reste affichée, et le message s\'affiche', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        verrouRattacherAvecEcritures($this, $ligne);

        // Comme l'application : l'événement « edit-transaction » appelle edit() avec l'identifiant.
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id);

        $index = array_search((int) $ligne->id, verrouIdsAffiches($formulaire), true);
        expect($index)->toBeInt();

        $formulaire->call('removeLigne', $index)
            ->assertHasErrors('lignes')
            ->assertSee(RattachementExerciceService::MOTIF_PIECE_GELEE);

        expect(verrouIdsAffiches($formulaire))->toContain((int) $ligne->id);
    })->with('rattachement_classes');

    test('retire du formulaire une ligne non rattachée d\'une autre pièce, alors qu\'un rattachement existe ailleurs', function () {
        // Une pièce rattachée, qui existe mais n'est pas celle du formulaire.
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree(7));

        // La pièce du formulaire : deux lignes de produit, aucune rattachée.
        $premiere = $this->ligneOrigineEquilibree(7);
        $seconde = verrouSoeur(7, $premiere);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $premiere->transaction_id);
        expect(verrouIdsAffiches($formulaire))->toBe([(int) $premiere->id, (int) $seconde->id]);

        $formulaire->call('removeLigne', 0)->assertHasNoErrors();

        expect(verrouIdsAffiches($formulaire))->toBe([(int) $seconde->id]);
    });

    test('retire du formulaire une ligne dont le rattachement a été retiré : la sortie annoncée par le refus fonctionne', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $provision = verrouRattacherAvecEcritures($this, $ligne);

        // Rattachée, elle est refusée (garde anti-test-vide : c'est bien le retrait qui la libère).
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id);
        $formulaire->call('removeLigne', 0)->assertHasErrors('lignes');
        expect(verrouIdsAffiches($formulaire))->toBe([(int) $ligne->id]);

        app(RattachementExerciceService::class)->retirer($provision);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id);
        $formulaire->call('removeLigne', 0)->assertHasNoErrors();

        expect(verrouIdsAffiches($formulaire))->toBe([]);
    });

    test('efface le message du refus quand on retire ensuite une ligne libre : il ne reste pas affiché après un autre geste', function () {
        $rattachee = $this->ligneOrigineEquilibree(7);
        $libre = verrouSoeur(7, $rattachee);
        verrouRattacherAvecEcritures($this, $rattachee);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $rattachee->transaction_id);
        expect(verrouIdsAffiches($formulaire))->toBe([(int) $rattachee->id, (int) $libre->id]);

        $formulaire->call('removeLigne', 0)
            ->assertHasErrors('lignes')
            ->assertSee(RattachementExerciceService::MOTIF_PIECE_GELEE);

        // Le geste suivant réussit : le message du précédent refus disparaît.
        $formulaire->call('removeLigne', 1)
            ->assertHasNoErrors('lignes')
            ->assertDontSee(RattachementExerciceService::MOTIF_PIECE_GELEE);

        expect(verrouIdsAffiches($formulaire))->toBe([(int) $rattachee->id]);
    });

    test('efface le message du refus quand on ajoute ensuite une ligne', function () {
        $rattachee = $this->ligneOrigineEquilibree(7);
        verrouRattacherAvecEcritures($this, $rattachee);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $rattachee->transaction_id);

        $formulaire->call('removeLigne', 0)
            ->assertHasErrors('lignes')
            ->assertSee(RattachementExerciceService::MOTIF_PIECE_GELEE);

        $formulaire->call('addLigne')
            ->assertHasNoErrors('lignes')
            ->assertDontSee(RattachementExerciceService::MOTIF_PIECE_GELEE);
    });
});
