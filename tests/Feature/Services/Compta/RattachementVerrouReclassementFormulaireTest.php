<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\GelRattachement;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Verrou d'une ligne rattachée : le reclassement et le retrait de ligne du formulaire (Task 7)
|--------------------------------------------------------------------------
|
| Deux gestes SUR UNE LIGNE (spec § 7) : ils ne refusent que la ligne rattachée ou la ligne d'une
| écriture de rattachement, jamais une ligne sœur. Un test par site tue l'appel posé à ce site, et
| celui-là seulement : retirer l'appel de reclasser() ne fait tomber que les tests de reclasser(),
| alors que casser la garde de GelRattachement fait tomber les siens. Tous les sites refusent avec le
| même message (GelRattachement::MOTIF_PIECE_GELEE), que ces tests comparent à VERROU_MESSAGE au mot
| près. Les helpers (VERROU_MESSAGE, verrou…()) vivent dans tests/Helpers/RattachementVerrou.php.
|
| Les gardes elles-mêmes : RattachementGelGardesTest.php.
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

    // La brèche trouvée en revue de la 8a : assertLigneNonGelee() ne demandait que « CETTE ligne porte-t-elle
    // un rattachement ? ». Une ligne d'écriture de rattachement n'en porte jamais, et reclasser() choisit la
    // classe attendue d'après le type de la pièce : la ligne de charge de la dotation d'une CCA (pièce de type
    // Dépense, écriture « normale ») passait tous ses contrôles, et se reclassait.
    test('refuse de reclasser la ligne de charge de la dotation d\'une CCA : son propre message, et la ligne comme les deux écritures n\'ont pas bougé', function () {
        $ligne = $this->ligneOrigineEquilibree(6);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $dotation = Transaction::where('provision_id', (int) $provision->id)->where('type_ecriture', 'normale')->sole();
        $ligneDeCharge = $dotation->lignes()->whereHas('compte', fn ($q) => $q->where('classe', 6))->sole();
        $autreCompte = verrouAutreCompte(6);

        // Garde anti-test-vide : la ligne visée est celle de la dotation (non celle de la pièce d'origine),
        // elle ne porte AUCUN rattachement, et le compte visé diffère de l'actuel.
        expect((int) $ligneDeCharge->transaction_id)->not->toBe((int) $ligne->transaction_id)
            ->and($ligneDeCharge->estRattachee())->toBeFalse()
            ->and((int) $autreCompte->id)->not->toBe((int) $ligneDeCharge->compte_id);

        verrouAssertRefuse(fn () => verrouReclasser($this, $ligneDeCharge, $autreCompte), $ligne, $provision, VERROU_MESSAGE_ECRITURE);

        $apres = $ligneDeCharge->fresh();
        expect((int) $apres->compte_id)->toBe((int) $ligneDeCharge->compte_id)
            ->and($apres->reclassee_at)->toBeNull();
    });

    // Les autres lignes des écritures de rattachement ne sont pas atteignables par le reclassement : la
    // dotation d'une PCA porte un produit (classe 7) sur une pièce de type Dépense, que reclasser() refuse
    // comme n'étant pas une ligne de charge ; leurs extournes sont refusées comme extournes. Aucune n'arrive
    // jusqu'à la garde. Le test l'épingle : le jour où l'un de ces refus disparaît, c'est la garde qui doit tenir.
    test('refuse de reclasser toute autre ligne d\'une écriture de rattachement : la dotation d\'une PCA, les extournes', function (int $classe, string $ecriture) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $piece = Transaction::where('provision_id', (int) $provision->id)->where('type_ecriture', $ecriture)->sole();
        $lignesDeResultat = $piece->lignes()->whereHas('compte', fn ($q) => $q->whereIn('classe', [6, 7]))->get();

        // Garde anti-test-vide : l'écriture porte bien une ligne de résultat à reclasser.
        expect($lignesDeResultat)->toHaveCount(1);

        foreach ($lignesDeResultat as $ligneDeResultat) {
            $refus = null;
            $avant = verrouEmpreinte($ligne, $provision);

            try {
                verrouReclasser($this, $ligneDeResultat, verrouAutreCompte(6));
            } catch (RuntimeException $e) {
                $refus = $e->getMessage();
            }

            expect($refus)->not->toBeNull()
                ->and(verrouEmpreinte($ligne, $provision))->toBe($avant);
        }
    })->with([
        'la dotation d\'une PCA' => [7, 'normale'],
        'l\'extourne d\'une PCA' => [7, 'extourne'],
        'l\'extourne d\'une CCA' => [6, 'extourne'],
    ]);

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
    // (TransactionService::update(), que la garde de la pièce protège, Task 8a). Ces tests lisent
    // donc l'ÉTAT DU COMPOSANT : une assertion sur la base passerait même sans garde.

    test('refuse de retirer du formulaire une ligne rattachée : elle reste affichée, et le message s\'affiche', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        verrouRattacherAvecEcritures($this, $ligne);

        // Comme l'application : l'événement « edit-transaction » appelle edit() avec l'identifiant.
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id);

        $index = array_search((int) $ligne->id, verrouIdsAffiches($formulaire), true);
        expect($index)->toBeInt();

        $formulaire->call('removeLigne', $index)
            ->assertHasErrors('lignes')
            ->assertSee(GelRattachement::MOTIF_PIECE_GELEE);

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
            ->assertSee(GelRattachement::MOTIF_PIECE_GELEE);

        // Le geste suivant réussit : le message du précédent refus disparaît.
        $formulaire->call('removeLigne', 1)
            ->assertHasNoErrors('lignes')
            ->assertDontSee(GelRattachement::MOTIF_PIECE_GELEE);

        expect(verrouIdsAffiches($formulaire))->toBe([(int) $rattachee->id]);
    });

    test('efface le message du refus quand on ajoute ensuite une ligne', function () {
        $rattachee = $this->ligneOrigineEquilibree(7);
        verrouRattacherAvecEcritures($this, $rattachee);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $rattachee->transaction_id);

        $formulaire->call('removeLigne', 0)
            ->assertHasErrors('lignes')
            ->assertSee(GelRattachement::MOTIF_PIECE_GELEE);

        $formulaire->call('addLigne')
            ->assertHasNoErrors('lignes')
            ->assertDontSee(GelRattachement::MOTIF_PIECE_GELEE);
    });
});
