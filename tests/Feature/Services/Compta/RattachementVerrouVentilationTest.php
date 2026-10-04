<?php

declare(strict_types=1);

use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Verrou d'une ligne rattachée : la ventilation (Task 8c)
|--------------------------------------------------------------------------
|
| Deux gestes SUR UNE LIGNE de TransactionService (spec § 7) : affecterLigne() et supprimerAffectations()
| écrivent les affectations d'une ligne. Comme le reclassement, ils ne refusent que la ligne rattachée
| (et celle d'une écriture de rattachement), jamais une ligne sœur : la garde est
| GelRattachement::assertLigneNonGelee(), prise dans leur transaction, après le verrou de l'exercice de
| la pièce et AVANT toute lecture ou écriture des affectations. C'est cet ordre, ligne puis affectations,
| qui est celui de delete() et d'annuler() (la garde de la pièce verrouille toutes les lignes, puis ils
| purgent les affectations) : plus d'inversion possible entre eux. Les helpers (VERROU_MESSAGE,
| verrouVentiler()…) vivent dans tests/Helpers/RattachementVerrou.php.
|
| Les gardes elles-mêmes : RattachementGelGardesTest.php.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486 et 487 : les comptes de régularisation, posés par le seeder système.
    SystemeSeeder::seed();
});

describe('TransactionService::affecterLigne() et supprimerAffectations() : une ligne rattachée n\'est pas ventilable', function () {
    test('refusent une ligne rattachée : le message exact, et ni la ligne, ni ses affectations, ni les écritures n\'ont bougé', function (int $classe, string $geste) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $ligne = $ligne->fresh();

        // Une affectation forgée après le rattachement : sans elle, un « supprimer » refusé ne se distinguerait pas
        // d'un « supprimer » qui n'a rien à supprimer.
        verrouSemerAffectation($ligne);
        expect(TransactionLigneAffectation::where('transaction_ligne_id', $ligne->id)->count())->toBe(1);

        verrouAssertRefuse(verrouVentiler($geste, $ligne), $ligne, $provision);

        expect(TransactionLigneAffectation::where('transaction_ligne_id', $ligne->id)->count())->toBe(1);
    })->with('rattachement_classes')->with('rattachement_gestes_ventilation');

    test('refusent la ligne rattachée même par une instance périmée, lue avant le rattachement', function (string $geste) {
        $ligne = $this->ligneOrigineEquilibree(7);
        // L'instance de l'appelant, lue AVANT le rattachement : elle ne sait rien de lui.
        $instance = TransactionLigne::findOrFail($ligne->id);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        verrouSemerAffectation($ligne);

        verrouAssertRefuse(verrouVentiler($geste, $instance), $ligne, $provision);
    })->with('rattachement_gestes_ventilation');

    test('refusent une ligne rattachée entre la lecture de l\'instance et le verrou : la garde lit sous verrou, dans la transaction', function (string $geste) {
        $ligne = $this->ligneOrigineEquilibree(7);
        verrouSemerAffectation($ligne);
        $ventiler = verrouVentiler($geste, $ligne);

        // Le rattachement arrive juste après la première requête de la transaction du geste (le verrou de
        // l'exercice), c'est-à-dire avant le verrou de la ligne : une garde posée avant la transaction ne le verrait pas.
        verrouChangementApresLaPremiereRequeteDeLaTransaction(fn () => provisionPourLigne($ligne));

        expect(verrouMessageDuRefus($ventiler))->toBe(VERROU_MESSAGE);
        expect(DB::table('transaction_ligne_affectations')->where('transaction_ligne_id', $ligne->id)->count())->toBe(1);
    })->with('rattachement_gestes_ventilation');

    test('laissent passer la ligne SŒUR de la ligne rattachée : la sémantique du verrou est par ligne, non par pièce', function (int $classe, string $geste) {
        ['libre' => $libre, 'soeur' => $soeur, 'provision' => $provision] = verrouPieceDontLaSoeurEstRattachee($this, $classe);
        $ancienne = verrouSemerAffectation($libre);
        $avantRattachee = verrouEmpreinte($soeur, $provision);

        verrouAssertPasse(verrouVentiler($geste, $libre), $libre);

        verrouAssertEffetVentilation($geste, $libre, (int) $ancienne->id);
        // Le geste n'a rien touché de ce que le rattachement porte.
        expect(verrouEmpreinte($soeur, $provision)['provision'])->toBe($avantRattachee['provision'])
            ->and(verrouEmpreinte($soeur, $provision)['ecritures'])->toBe($avantRattachee['ecritures'])
            ->and(verrouEmpreinte($soeur, $provision)['lignes'])->toBe($avantRattachee['lignes'])
            ->and(DB::table('transaction_ligne_affectations')->where('transaction_ligne_id', $soeur->id)->count())->toBe(0);
    })->with('rattachement_classes')->with('rattachement_gestes_ventilation');

    test('laissent passer une ligne libre, alors qu\'un rattachement existe sur une autre pièce : la garde regarde cette ligne', function (int $classe, string $geste) {
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree($classe));

        $libre = $this->ligneOrigineEquilibree($classe);
        $ancienne = verrouSemerAffectation($libre);

        verrouAssertPasse(verrouVentiler($geste, $libre), $libre);

        verrouAssertEffetVentilation($geste, $libre, (int) $ancienne->id);
    })->with('rattachement_classes')->with('rattachement_gestes_ventilation');

    test('laissent passer une ligne dont le rattachement a été retiré : la sortie annoncée par le refus fonctionne', function (int $classe, string $geste) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $ligne = $ligne->fresh();
        $ancienne = verrouSemerAffectation($ligne);

        // Rattachée, elle est refusée (garde anti-test-vide : c'est bien le retrait qui la libère).
        expect(verrouMessageDuRefus(verrouVentiler($geste, $ligne)))->toBe(VERROU_MESSAGE);

        app(RattachementExerciceService::class)->retirer($provision);

        verrouAssertPasse(verrouVentiler($geste, $ligne->fresh()), $ligne);

        verrouAssertEffetVentilation($geste, $ligne, (int) $ancienne->id);
    })->with('rattachement_classes')->with('rattachement_gestes_ventilation');

    test('refusent une ligne d\'écriture de rattachement, dotation ou extourne : son propre message, et rien n\'a bougé', function (int $classe, string $ecriture, string $geste) {
        ['ligne' => $origine, 'provision' => $provision, 'ecriture' => $ligneDeLEcriture] = verrouEcritureDeRattachement($this, $classe, $ecriture);
        verrouSemerAffectation($ligneDeLEcriture);

        verrouAssertRefuse(verrouVentiler($geste, $ligneDeLEcriture), $origine, $provision, VERROU_MESSAGE_ECRITURE);

        expect(DB::table('transaction_ligne_affectations')->where('transaction_ligne_id', $ligneDeLEcriture->id)->count())->toBe(1);
    })->with('rattachement_classes')->with([
        'la dotation' => ['normale'],
        'l\'extourne' => ['extourne'],
    ])->with('rattachement_gestes_ventilation');
});

describe('TransactionService::affecterLigne() et supprimerAffectations() : l\'ordre des verrous', function () {
    // L'ordre de delete() et d'annuler() : l'exercice, puis les lignes (la garde de la pièce les verrouille toutes),
    // les rattachements, et PLUS TARD les affectations (purgerLignesEtAffectations()). La ventilation prend le même :
    // l'exercice, LA ligne, ses rattachements, puis ses affectations. Les deux ne se croisent donc jamais en sens inverse.
    test('refus : l\'exercice, la ligne, les rattachements — et rien après, les affectations ne sont jamais atteintes (MariaDB)', function (string $geste) {
        $ligne = $this->ligneOrigineEquilibree(7);
        verrouRattacherAvecEcritures($this, $ligne);
        $ligne = $ligne->fresh();
        verrouSemerAffectation($ligne);
        $ventiler = verrouVentiler($geste, $ligne);

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => expect($ventiler)->toThrow(RuntimeException::class, VERROU_MESSAGE)
        );

        $indices = verrouIndicesDeLaVentilation($requetes);

        expect(array_keys($indices))->toBe(['exercice', 'ligne', 'rattachements'])
            ->and(array_values($indices))->toBe(collect($indices)->sort()->values()->all());

        // Le refus précède toute lecture et toute écriture des affectations : une garde posée après les ferait
        // apparaître ici, puis la transaction les annulerait, et le seul état final ne le trahirait pas.
        // En texte : une comparaison qui échoue ne doit pas avoir à exporter les objets de requête.
        expect(array_values(array_map(
            fn (object $r): string => $r->sql,
            array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'transaction_ligne_affectations')),
        )))->toBe([]);

        verrouAssertVerrousPourMariaDb($requetes, $indices);
    })->with('rattachement_gestes_ventilation');

    test('passage : l\'exercice, la ligne, les rattachements, PUIS les affectations (MariaDB)', function (string $geste) {
        $ligne = $this->ligneOrigineEquilibree(7);
        verrouSemerAffectation($ligne);
        $ventiler = verrouVentiler($geste, $ligne);

        $requetes = rattachementRequetesDeLaTransaction($ventiler);

        $indices = verrouIndicesDeLaVentilation($requetes);
        $indices['affectations'] = rattachementIndiceDeLaPremiere($requetes, 'transaction_ligne_affectations');

        expect(array_keys($indices))->toBe(['exercice', 'ligne', 'rattachements', 'affectations'])
            ->and(array_values($indices))->toBe(collect($indices)->sort()->values()->all())
            ->and(count(array_unique($indices)))->toBe(4);

        verrouAssertVerrousPourMariaDb($requetes, array_slice($indices, 0, 3, true));
    })->with('rattachement_gestes_ventilation');
});

/**
 * Les trois verrous de la garde d'une ligne dans les requêtes d'une transaction : le verrou de l'exercice de la
 * pièce (celui de 2025, la pièce est datée du 08/04/2026), la lecture verrouillante de la ligne par son identifiant,
 * et la lecture verrouillante des rattachements. Chacun est lu UNE fois, dans l'ordre où ils sont pris.
 *
 * @param  list<object>  $requetes
 * @return array<string, int>
 */
function verrouIndicesDeLaVentilation(array $requetes): array
{
    $indiceLigne = null;
    $nbLignes = 0;
    foreach ($requetes as $i => $requete) {
        if (preg_match('/from\s+[`"]transaction_lignes[`"]\s+where\s+[`"]transaction_lignes[`"]\.[`"]id[`"]\s*=\s*\?/i', $requete->sql) === 1) {
            $indiceLigne ??= $i;
            $nbLignes++;
        }
    }

    // Garde anti-test-vide : la ligne est lue par son identifiant, une seule fois.
    expect($indiceLigne)->toBeInt()
        ->and($nbLignes)->toBe(1);

    $indices = [
        'exercice' => rattachementIndiceDeLaPremiere($requetes, 'exercices', 2025),
        'ligne' => $indiceLigne,
        'rattachements' => rattachementIndiceDeLaPremiere($requetes, 'provisions'),
    ];

    // Une lecture verrouillante DIRECTE des rattachements, pas un EXISTS ; une seule.
    expect(mb_strtolower($requetes[$indices['rattachements']]->sql))->not->toContain('exists')
        ->and(array_filter(array_map(fn (object $r): string => $r->sql, $requetes), fn (string $sql): bool => str_contains($sql, 'provisions')))->toHaveCount(1);

    return $indices;
}

/**
 * SQLite n'écrit pas « for update » : seul MySQL/MariaDB prouve que ces lectures verrouillent.
 *
 * @param  list<object>  $requetes
 * @param  array<string, int>  $indices
 */
function verrouAssertVerrousPourMariaDb(array $requetes, array $indices): void
{
    if (DB::getDriverName() === 'sqlite') {
        return;
    }

    foreach ($indices as $i) {
        expect($requetes[$i]->sql)->toContain('for update');
    }
}
