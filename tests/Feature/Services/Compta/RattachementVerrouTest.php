<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Models\Compte;
use App\Models\Provision;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Services\Compta\ReclassementLigneService;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Verrou d'une ligne rattachée à l'exercice (CCA/PCA)
|--------------------------------------------------------------------------
|
| Une ligne portée par un rattachement gèle sa pièce entière (spec § 7).
| TransactionLigne::estRattachee() est la seule source de vérité de ce verrou : les
| gardes d'écriture (mise à jour, suppression, annulation, ventilation, extourne,
| reclassement, synchronisation HelloAsso) des Tasks 7 à 9 l'appelleront, ou poseront
| exactement la même question. Ce fichier accueille aussi les tests de ces gardes, un par
| site : reclassement et retrait de ligne (Task 7), puis les suivants à mesure.
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
        // pièce entière (§ 7) est ce que la garde de transaction des Tasks 7 à 9 devra
        // compenser en interrogeant chacune de ses lignes.
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

// ── Les sites du verrou (spec § 7, AC-7) ─────────────────────────────────────
//
// Un test par site, qui tue une garde précise. Tous les sites refusent avec le même message
// (RattachementExerciceService::MOTIF_PIECE_GELEE), que ce fichier épingle au mot près une
// fois, dans le premier test : les suivants le comparent à la constante.

/** Le message unique du verrou, mot pour mot : ce que l'utilisateur lit. */
const VERROU_MESSAGE = 'Cette pièce porte un rattachement à l’exercice suivant : pour la modifier, retirez d’abord le rattachement '
    .'depuis Exercices > Écritures de rattachement, corrigez, puis repassez-le.';

/**
 * Une ligne d'origine sur une pièce ÉQUILIBRÉE : la ligne de produit (classe 7) ou de charge
 * (classe 6) de ligneOrigine(), et sa contrepartie 411 au débit ou 401 au crédit. Reclasser
 * vérifie l'équilibre de la pièce après écriture (PartieDoubleGuard), ce que la ligne seule
 * de ligneOrigine() ne permet pas.
 */
function verrouLigneEquilibree(object $test, int $classe, float $montant = 175.0): TransactionLigne
{
    $ligne = $test->ligneOrigine($classe, $montant);
    $contrepartie = Compte::where('numero_pcg', $classe === 7 ? '411' : '401')->firstOrFail();

    TransactionLigne::create([
        'transaction_id' => $ligne->transaction_id,
        'compte_id' => $contrepartie->id,
        'debit' => $classe === 7 ? $montant : 0,
        'credit' => $classe === 6 ? $montant : 0,
        'montant' => 0,
        'libelle' => 'Contrepartie',
    ]);

    return $ligne;
}

/** Un compte actif de la même classe que la ligne d'origine, vers lequel la reclasser. */
function verrouAutreCompte(int $classe): Compte
{
    return ($classe === 7 ? Compte::factory()->recette() : Compte::factory()->depense())
        ->create(['association_id' => (int) TenantContext::currentId(), 'actif' => true]);
}

/** Le message du refus que lève $action, ou null si elle ne lève pas de RuntimeException. */
function verrouMessageDuRefus(Closure $action): ?string
{
    try {
        $action();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return null;
}

/**
 * Ce qu'un reclassement refusé n'a pas le droit de toucher : la ligne d'origine, et les deux
 * écritures du rattachement avec leurs lignes. Lignes complètes, relevées sans aucun filtre.
 *
 * @return array{ligne: array<string, mixed>, ecritures: list<array<string, mixed>>, lignes: list<array<string, mixed>>}
 */
function verrouEmpreinte(TransactionLigne $ligne, Provision $provision): array
{
    $ecritureIds = DB::table('transactions')->where('provision_id', (int) $provision->id)->orderBy('id')->pluck('id')->all();

    return [
        'ligne' => (array) DB::table('transaction_lignes')->where('id', (int) $ligne->id)->first(),
        'ecritures' => DB::table('transactions')->whereIn('id', $ecritureIds)->orderBy('id')->get()
            ->map(fn (object $ecriture): array => (array) $ecriture)->all(),
        'lignes' => DB::table('transaction_lignes')->whereIn('transaction_id', $ecritureIds)->orderBy('id')->get()
            ->map(fn (object $l): array => (array) $l)->all(),
    ];
}

/** @return list<int> les identifiants des lignes que le formulaire de transaction affiche */
function verrouIdsAffiches(Testable $formulaire): array
{
    return collect($formulaire->get('lignes'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

describe('ReclassementLigneService::reclasser() : une ligne rattachée n\'est pas reclassable', function () {
    test('refuse de reclasser une ligne rattachée : le message exact, et la ligne comme les deux écritures n\'ont pas bougé', function (int $classe) {
        $ligne = verrouLigneEquilibree($this, $classe);
        $autreCompte = verrouAutreCompte($classe);
        $provision = app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);
        $avant = verrouEmpreinte($ligne, $provision);

        // Gardes anti-test-vide : la ligne est rattachée, le rattachement a ses deux écritures et
        // leurs quatre lignes, et le compte visé diffère bien de l'actuel.
        expect($ligne->fresh()->estRattachee())->toBeTrue()
            ->and($avant['ecritures'])->toHaveCount(2)
            ->and($avant['lignes'])->toHaveCount(4)
            ->and((int) $autreCompte->id)->not->toBe((int) $ligne->compte_id);

        $message = verrouMessageDuRefus(fn () => app(ReclassementLigneService::class)->reclasser(
            $ligne, (int) $autreCompte->id, null, null, 'Motif de reclassement suffisant', $this->user,
        ));

        expect($message)->toBe(VERROU_MESSAGE)
            ->and(RattachementExerciceService::MOTIF_PIECE_GELEE)->toBe(VERROU_MESSAGE);

        // La ligne n'a pas bougé : compte, opération, séance, marque de reclassement.
        $apres = $ligne->fresh();
        expect((int) $apres->compte_id)->toBe((int) $ligne->compte_id)
            ->and($apres->operation_id)->toBeNull()
            ->and($apres->reclassee_at)->toBeNull()
            ->and($apres->reclassement_compte_origine_id)->toBeNull();

        // Et rien d'autre : la ligne en base, et les écritures du rattachement, à l'identique.
        expect(verrouEmpreinte($ligne, $provision))->toBe($avant);
    })->with([
        'charge (classe 6, CCA)' => [6],
        'produit (classe 7, PCA)' => [7],
    ]);

    test('reclasse une ligne non rattachée de la même forme : la garde ne bloque pas ce qu\'elle n\'a pas à bloquer', function (int $classe) {
        $ligne = verrouLigneEquilibree($this, $classe);
        $autreCompte = verrouAutreCompte($classe);

        // Un rattachement existe ailleurs : la garde regarde cette ligne, pas « un rattachement ».
        app(RattachementExerciceService::class)->rattacher(verrouLigneEquilibree($this, $classe), null, $this->user);

        expect($ligne->estRattachee())->toBeFalse();

        app(ReclassementLigneService::class)->reclasser(
            $ligne, (int) $autreCompte->id, null, null, 'Motif de reclassement suffisant', $this->user,
        );

        $apres = $ligne->fresh();
        expect((int) $apres->compte_id)->toBe((int) $autreCompte->id)
            ->and($apres->reclassee_at)->not->toBeNull();
    })->with([
        'charge (classe 6, CCA)' => [6],
        'produit (classe 7, PCA)' => [7],
    ]);

    test('reclasse une ligne dont le rattachement a été retiré : la sortie annoncée par le refus fonctionne', function (int $classe) {
        $ligne = verrouLigneEquilibree($this, $classe);
        $autreCompte = verrouAutreCompte($classe);
        $service = app(RattachementExerciceService::class);
        $provision = $service->rattacher($ligne, null, $this->user);

        // Rattachée, elle est refusée (garde anti-test-vide : c'est bien le retrait qui la libère).
        expect(verrouMessageDuRefus(fn () => app(ReclassementLigneService::class)->reclasser(
            $ligne, (int) $autreCompte->id, null, null, 'Motif de reclassement suffisant', $this->user,
        )))->toBe(VERROU_MESSAGE);

        $service->retirer($provision);

        app(ReclassementLigneService::class)->reclasser(
            $ligne->fresh(), (int) $autreCompte->id, null, null, 'Motif de reclassement suffisant', $this->user,
        );

        expect((int) $ligne->fresh()->compte_id)->toBe((int) $autreCompte->id);
    })->with([
        'charge (classe 6, CCA)' => [6],
        'produit (classe 7, PCA)' => [7],
    ]);

    test('lit les rattachements par une lecture verrouillante directe (« for update », sans EXISTS), après le verrou de la ligne, elle-même après celui de l\'exercice', function () {
        $ligne = verrouLigneEquilibree($this, 7);
        $autreCompte = verrouAutreCompte(7);
        app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);
        $ligne = $ligne->fresh();

        $requetes = verrouRequetesDeLaTransaction(
            fn () => expect(fn () => app(ReclassementLigneService::class)->reclasser(
                $ligne, (int) $autreCompte->id, null, null, 'Motif de reclassement suffisant', $this->user,
            ))->toThrow(RuntimeException::class, VERROU_MESSAGE)
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
            'exercice' => serviceRattachementIndiceDeLaPremiere($requetes, 'exercices', 2025),
            'ligne' => $indiceLigne,
            'rattachements' => serviceRattachementIndiceDeLaPremiere($requetes, 'provisions'),
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
    // (TransactionService::update(), gardé par ailleurs). Ces tests lisent donc l'ÉTAT DU COMPOSANT :
    // une assertion sur la base passerait même sans garde.

    test('refuse de retirer du formulaire une ligne rattachée : elle reste affichée, et le message s\'affiche', function (int $classe) {
        $ligne = verrouLigneEquilibree($this, $classe);
        app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        // Comme l'application : l'événement « edit-transaction » appelle edit() avec l'identifiant.
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id);

        $index = array_search((int) $ligne->id, verrouIdsAffiches($formulaire), true);
        expect($index)->toBeInt();

        $formulaire->call('removeLigne', $index)
            ->assertHasErrors('lignes')
            ->assertSee(RattachementExerciceService::MOTIF_PIECE_GELEE);

        expect(verrouIdsAffiches($formulaire))->toContain((int) $ligne->id);
    })->with([
        'charge (classe 6, CCA)' => [6],
        'produit (classe 7, PCA)' => [7],
    ]);

    test('retire du formulaire une ligne non rattachée d\'une autre pièce, alors qu\'un rattachement existe ailleurs', function () {
        // Une pièce rattachée, qui existe mais n'est pas celle du formulaire.
        app(RattachementExerciceService::class)->rattacher(verrouLigneEquilibree($this, 7), null, $this->user);

        // La pièce du formulaire : deux lignes de produit, aucune rattachée.
        $premiere = verrouLigneEquilibree($this, 7);
        $compte = Compte::factory()->recette()->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
        $seconde = TransactionLigne::create([
            'transaction_id' => $premiere->transaction_id,
            'compte_id' => $compte->id,
            'debit' => 0,
            'credit' => 50,
            'montant' => 50,
            'libelle' => 'Seconde ligne',
        ]);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $premiere->transaction_id);
        expect(verrouIdsAffiches($formulaire))->toBe([(int) $premiere->id, (int) $seconde->id]);

        $formulaire->call('removeLigne', 0)->assertHasNoErrors();

        expect(verrouIdsAffiches($formulaire))->toBe([(int) $seconde->id]);
    });

    test('retire du formulaire une ligne dont le rattachement a été retiré : la sortie annoncée par le refus fonctionne', function () {
        $ligne = verrouLigneEquilibree($this, 7);
        $service = app(RattachementExerciceService::class);
        $provision = $service->rattacher($ligne, null, $this->user);

        // Rattachée, elle est refusée (garde anti-test-vide : c'est bien le retrait qui la libère).
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id);
        $formulaire->call('removeLigne', 0)->assertHasErrors('lignes');
        expect(verrouIdsAffiches($formulaire))->toBe([(int) $ligne->id]);

        $service->retirer($provision);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id);
        $formulaire->call('removeLigne', 0)->assertHasNoErrors();

        expect(verrouIdsAffiches($formulaire))->toBe([]);
    });
});
