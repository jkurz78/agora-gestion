<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\RattachementExerciceService;
use App\Services\Compta\ReclassementLigneService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Helpers du verrou d'une ligne rattachée (CCA/PCA, spec § 7)
|--------------------------------------------------------------------------
|
| Partagés par les tests des sites du verrou : le reclassement et le retrait de ligne (Task 7),
| l'édition, la suppression et l'annulation (Task 8a), puis l'extourne, la ventilation et la
| synchronisation HelloAsso. Pest charge tests/Helpers pour toute exécution, d'un seul fichier comme
| de la suite complète : déclarés dans un fichier de test, ces helpers manqueraient aux autres.
*/

/** Le message unique du verrou, mot pour mot : ce que l'utilisateur lit. */
const VERROU_MESSAGE = 'Cette pièce porte un rattachement à l’exercice suivant : pour la modifier, retirez d’abord le rattachement '
    .'depuis Exercices > Écritures de rattachement, corrigez, puis repassez-le.';

/** Un compte actif de la même classe que la ligne d'origine, vers lequel la reclasser. */
function verrouAutreCompte(int $classe): Compte
{
    return ($classe === 7 ? Compte::factory()->recette() : Compte::factory()->depense())
        ->create(['association_id' => (int) TenantContext::currentId(), 'actif' => true]);
}

/**
 * Une ligne sœur de $ligne, de la même pièce et de la même classe, sur un autre compte : la
 * sémantique du verrou est par ligne, son gel est celui de la pièce.
 */
function verrouSoeur(int $classe, TransactionLigne $ligne, float $montant = 50.0): TransactionLigne
{
    return TransactionLigne::create([
        'transaction_id' => $ligne->transaction_id,
        'compte_id' => verrouAutreCompte($classe)->id,
        'debit' => $classe === 6 ? $montant : 0,
        'credit' => $classe === 7 ? $montant : 0,
        'montant' => $montant,
        'libelle' => 'Ligne sœur',
    ]);
}

/**
 * Rattache pour de bon (rattacher() : le rattachement et ses deux écritures) la ligne $ligne.
 */
function verrouRattacherAvecEcritures(TestCase $test, TransactionLigne $ligne): Provision
{
    return app(RattachementExerciceService::class)->rattacher($ligne, null, $test->user);
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
 * Ce qu'un geste refusé n'a pas le droit de toucher : la pièce de la ligne et TOUTES ses lignes
 * (les gestes des Tasks 8 et 9 touchent la pièce entière), le rattachement, et les deux écritures
 * du rattachement avec leurs lignes. Lignes complètes, relevées sans aucun filtre : suppressions
 * logiques comprises.
 *
 * @return array{piece: array<string, mixed>, lignes_de_la_piece: list<array<string, mixed>>, provision: array<string, mixed>, ecritures: list<array<string, mixed>>, lignes: list<array<string, mixed>>}
 */
function verrouEmpreinte(TransactionLigne $ligne, Provision $provision): array
{
    $pieceId = (int) DB::table('transaction_lignes')->where('id', (int) $ligne->id)->value('transaction_id');
    $ecritureIds = DB::table('transactions')->where('provision_id', (int) $provision->id)->orderBy('id')->pluck('id')->all();

    return [
        'piece' => (array) DB::table('transactions')->where('id', $pieceId)->first(),
        'lignes_de_la_piece' => DB::table('transaction_lignes')->where('transaction_id', $pieceId)->orderBy('id')->get()
            ->map(fn (object $l): array => (array) $l)->all(),
        'provision' => (array) DB::table('provisions')->where('id', (int) $provision->id)->first(),
        'ecritures' => DB::table('transactions')->whereIn('id', $ecritureIds)->orderBy('id')->get()
            ->map(fn (object $ecriture): array => (array) $ecriture)->all(),
        'lignes' => DB::table('transaction_lignes')->whereIn('transaction_id', $ecritureIds)->orderBy('id')->get()
            ->map(fn (object $l): array => (array) $l)->all(),
    ];
}

/**
 * Le geste d'un site du verrou est REFUSÉ : par le message exact du verrou, et sans que rien n'ait
 * bougé (verrouEmpreinte() à l'identique). $ligne est la ligne rattachée, $provision son rattachement.
 */
function verrouAssertRefuse(Closure $geste, TransactionLigne $ligne, Provision $provision): void
{
    $avant = verrouEmpreinte($ligne, $provision);

    // Gardes anti-test-vide : le rattachement existe, avec ses deux écritures et leurs quatre lignes.
    expect($avant['provision'])->not->toBeEmpty()
        ->and($avant['ecritures'])->toHaveCount(2)
        ->and($avant['lignes'])->toHaveCount(4);

    expect(verrouMessageDuRefus($geste))->toBe(VERROU_MESSAGE)
        ->and(RattachementExerciceService::MOTIF_PIECE_GELEE)->toBe(VERROU_MESSAGE);

    expect(verrouEmpreinte($ligne, $provision))->toBe($avant);
}

/**
 * Le geste d'un site du verrou PASSE sur une ligne libre : il ne lève rien. L'effet du geste est à
 * la charge de l'appelant.
 */
function verrouAssertPasse(Closure $geste, TransactionLigne $ligne): void
{
    // Garde anti-test-vide : la ligne est bien libre. Relue avec ses suppressions logiques.
    expect(TransactionLigne::withTrashed()->findOrFail($ligne->id)->estRattachee())->toBeFalse();

    expect(verrouMessageDuRefus($geste))->toBeNull();
}

/**
 * Les requêtes de verrou de la garde ne portent AUCUN scope : ni suppression logique, ni tenant
 * (association_id si le contexte est booté, « 1 = 0 » s'il ne l'est pas). C'est la même forme de
 * lecture qui, sans cela, rendrait la garde passante sur une ligne supprimée ou hors contexte.
 */
function verrouAssertSansScope(object $requete): void
{
    expect($requete->sql)->not->toContain('deleted_at')
        ->and($requete->sql)->not->toContain('association_id')
        ->and($requete->sql)->not->toContain('1 = 0');
}

/**
 * Une pièce équilibrée dont la ligne SŒUR (la dernière, après la contrepartie) est rattachée, et sa
 * ligne d'origine, libre.
 *
 * @return array{libre: TransactionLigne, soeur: TransactionLigne, provision: Provision, piece: Transaction} la ligne
 *                                                                                                           libre, la sœur rattachée, son
 *                                                                                                           rattachement, la pièce
 */
function verrouPieceDontLaSoeurEstRattachee(TestCase $test, int $classe): array
{
    $libre = $test->ligneOrigineEquilibree($classe);
    $soeur = verrouSoeur($classe, $libre);

    return [
        'libre' => $libre,
        'soeur' => $soeur,
        'provision' => verrouRattacherAvecEcritures($test, $soeur),
        'piece' => Transaction::findOrFail($libre->transaction_id),
    ];
}

/** Le geste du site de ReclassementLigneService : reclasser $ligne vers $compte, avec un motif suffisant. */
function verrouReclasser(TestCase $test, TransactionLigne $ligne, Compte $compte): TransactionLigne
{
    return app(ReclassementLigneService::class)->reclasser(
        $ligne, (int) $compte->id, null, null, 'Motif de reclassement suffisant', $test->user,
    );
}

/** @return list<int> les identifiants des lignes que le formulaire de transaction affiche */
function verrouIdsAffiches(Testable $formulaire): array
{
    return collect($formulaire->get('lignes'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
}
