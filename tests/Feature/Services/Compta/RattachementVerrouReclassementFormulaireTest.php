<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutOperation;
use App\Livewire\TransactionForm;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\GelRattachement;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // Le geste suivant réussit : le message du précédent refus disparaît. La pièce est gelée, donc son
        // motif reste affiché en tête du formulaire, une fois : c'est le refus, et lui seul, qui disparaît.
        expect(verrouOccurrencesDuMotif($formulaire, GelRattachement::MOTIF_PIECE_GELEE))->toBe(2);

        $formulaire->call('removeLigne', 1)
            ->assertHasNoErrors('lignes');

        expect(verrouOccurrencesDuMotif($formulaire, GelRattachement::MOTIF_PIECE_GELEE))->toBe(1)
            ->and(verrouIdsAffiches($formulaire))->toBe([(int) $rattachee->id]);
    });

    test('efface le message du refus quand on ajoute ensuite une ligne', function () {
        $rattachee = $this->ligneOrigineEquilibree(7);
        verrouRattacherAvecEcritures($this, $rattachee);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $rattachee->transaction_id);

        $formulaire->call('removeLigne', 0)
            ->assertHasErrors('lignes')
            ->assertSee(GelRattachement::MOTIF_PIECE_GELEE);

        // Même remarque : la pièce est gelée, son motif reste affiché en tête du formulaire (une fois) ;
        // le refus, lui, ne s'ajoute plus à lui.
        expect(verrouOccurrencesDuMotif($formulaire, GelRattachement::MOTIF_PIECE_GELEE))->toBe(2);

        $formulaire->call('addLigne')
            ->assertHasNoErrors('lignes');

        expect(verrouOccurrencesDuMotif($formulaire, GelRattachement::MOTIF_PIECE_GELEE))->toBe(1);
    });
});

describe('TransactionForm::edit() : une pièce rattachée s\'ouvre gelée', function () {
    // Le gel de l'ouverture est une PRÉVENTION : il épargne à l'utilisateur de saisir ce que l'enregistrement
    // refusera. L'autorité reste au service (verrouillerPieceNonGelee(), sous verrou) ; le formulaire, lui, lit
    // sans verrou. Il porte sur la pièce entière, comme l'enregistrement qu'il prévient.
    test('affiche le motif dès l\'ouverture d\'une pièce dont une ligne est rattachée, et passe en lecture seule : plus de bouton d\'enregistrement', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        verrouRattacherAvecEcritures($this, $ligne);

        // Aucun geste, aucun refus : le motif est là parce que la pièce est gelée.
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id)
            ->assertHasNoErrors()
            ->assertSee(GelRattachement::MOTIF_PIECE_GELEE)
            ->assertDontSee(GelRattachement::MOTIF_ECRITURE_DE_RATTACHEMENT);

        expect(verrouOccurrencesDuMotif($formulaire, GelRattachement::MOTIF_PIECE_GELEE))->toBe(1);
        verrouAssertLectureSeule($formulaire);
    })->with('rattachement_classes');

    test('gèle la pièce entière : la ligne sœur d\'une ligne rattachée ne rend pas la pièce libre', function () {
        ['piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $piece->id)
            ->assertSee(GelRattachement::MOTIF_PIECE_GELEE);

        verrouAssertLectureSeule($formulaire);
    });

    test('gèle une écriture de rattachement, dotation ou extourne générée : son propre motif', function (int $classe, string $ecriture) {
        ['piece' => $piece] = verrouEcritureDeRattachement($this, $classe, $ecriture);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $piece->id)
            ->assertSee(GelRattachement::MOTIF_ECRITURE_DE_RATTACHEMENT)
            ->assertDontSee(GelRattachement::MOTIF_PIECE_GELEE);

        verrouAssertLectureSeule($formulaire);
    })->with('rattachement_classes')->with([
        'la dotation' => ['normale'],
        'l\'extourne' => ['extourne'],
    ]);

    test('save() refuse côté serveur, avant toute validation, et sans rien écrire : l\'absence du bouton ne suffit pas', function (int $classe) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $avant = verrouEmpreinte($ligne, $provision);

        // Un formulaire INVALIDE (aucun tiers) : sans refus préalable, la validation parlerait d'abord. C'est ce
        // qui distingue le refus du formulaire de celui du service, que save() atteindrait de toute façon ensuite.
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id)
            ->set('libelle', 'Libellé modifié')
            ->set('tiers_id', null)
            ->call('save')
            ->assertHasErrors('lignes')
            ->assertHasNoErrors('tiers_id');

        expect($formulaire->errors()->get('lignes'))->toBe([GelRattachement::MOTIF_PIECE_GELEE])
            ->and(verrouEmpreinte($ligne, $provision))->toBe($avant);
    })->with('rattachement_classes');

    test('save() refuse aussi une écriture de rattachement, avec son propre motif, et sans rien écrire', function () {
        ['ligne' => $origine, 'provision' => $provision, 'piece' => $piece] = verrouEcritureDeRattachement($this, 7, 'normale');
        $avant = verrouEmpreinte($origine, $provision);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $piece->id)
            ->set('libelle', 'Libellé modifié')
            ->set('tiers_id', null)
            ->call('save')
            ->assertHasNoErrors('tiers_id');

        expect($formulaire->errors()->get('lignes'))->toBe([GelRattachement::MOTIF_ECRITURE_DE_RATTACHEMENT])
            ->and(verrouEmpreinte($origine, $provision))->toBe($avant);
    });

    test('ne gèle pas une pièce libre, alors qu\'un rattachement existe sur une autre pièce : ni motif, ni lecture seule', function () {
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree(7));
        $libre = $this->ligneOrigineEquilibree(7);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $libre->transaction_id)
            ->assertDontSee(GelRattachement::MOTIF_PIECE_GELEE)
            ->assertDontSee(GelRattachement::MOTIF_ECRITURE_DE_RATTACHEMENT);

        verrouAssertEditable($formulaire);
    });

    test('enregistre une pièce libre : le refus préalable ne bloque que la pièce gelée', function () {
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree(7));
        $libre = $this->ligneOrigineEquilibree(7);

        Livewire::test(TransactionForm::class)->call('edit', (int) $libre->transaction_id)
            ->set('libelle', 'Libellé corrigé')
            ->set('tiers_id', Tiers::factory()->create()->id)
            ->set('paiementRecu', false)
            ->call('save')
            ->assertHasNoErrors();

        expect(Transaction::findOrFail($libre->transaction_id)->libelle)->toBe('Libellé corrigé');
    });

    test('ne gèle plus la pièce dont le rattachement a été retiré : le motif disparaît à la réouverture, dans le même composant', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $provision = verrouRattacherAvecEcritures($this, $ligne);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id)
            ->assertSee(GelRattachement::MOTIF_PIECE_GELEE);
        verrouAssertLectureSeule($formulaire);

        app(RattachementExerciceService::class)->retirer($provision);

        $formulaire->call('edit', (int) $ligne->transaction_id)
            ->assertDontSee(GelRattachement::MOTIF_PIECE_GELEE)
            ->assertSet('lignesGelees', []);
        verrouAssertEditable($formulaire);
    });

    test('save() relit le gel : un rattachement posé depuis l\'ouverture refuse, un rattachement retiré depuis l\'ouverture ne bloque plus', function () {
        // Posé après l'ouverture d'une pièce libre : le formulaire ne le sait pas, save() le relit (et refuse avant toute validation).
        $ligne = $this->ligneOrigineEquilibree(7);
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id)
            ->assertDontSee(GelRattachement::MOTIF_PIECE_GELEE);
        $provision = verrouRattacherAvecEcritures($this, $ligne);

        $formulaire->set('tiers_id', null)->call('save')->assertHasNoErrors('tiers_id');
        expect($formulaire->errors()->get('lignes'))->toBe([GelRattachement::MOTIF_PIECE_GELEE]);
        verrouAssertLectureSeule($formulaire);

        // Retiré après l'ouverture : le motif affiché est périmé, save() ne s'y fie pas et enregistre.
        app(RattachementExerciceService::class)->retirer($provision);

        $formulaire->set('tiers_id', Tiers::factory()->create()->id)
            ->set('libelle', 'Libellé après retrait')
            ->set('paiementRecu', false)
            ->call('save')
            ->assertHasNoErrors();

        expect(Transaction::findOrFail($ligne->transaction_id)->libelle)->toBe('Libellé après retrait');
    });

    test('ne garde pas le gel d\'une pièce pour la suivante : une nouvelle saisie, ou la fermeture, le lèvent', function (string $geste) {
        $ligne = $this->ligneOrigineEquilibree(7);
        verrouRattacherAvecEcritures($this, $ligne);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id)
            ->assertSee(GelRattachement::MOTIF_PIECE_GELEE);

        // Le motif lui-même est remis à zéro, qu'il soit rendu ou non : après la fermeture, le formulaire n'est plus affiché.
        $formulaire->call($geste, ...($geste === 'showNewForm' ? ['depense'] : []))
            ->assertDontSee(GelRattachement::MOTIF_PIECE_GELEE)
            ->assertSet('motifGel', null)
            ->assertSet('lignesGelees', []);

        // Rouvert sur une pièce libre dans le même composant : toujours pas gelé.
        if ($geste === 'resetForm') {
            $libre = $this->ligneOrigineEquilibree(7);
            $formulaire->call('edit', (int) $libre->transaction_id)->assertDontSee(GelRattachement::MOTIF_PIECE_GELEE);
        } else {
            verrouAssertEditable($formulaire);
        }
    })->with([
        'une nouvelle saisie' => ['showNewForm'],
        'la fermeture du formulaire' => ['resetForm'],
    ]);
});

describe('TransactionForm : la ventilation d\'une pièce gelée ne refuse que la ligne rattachée', function () {
    // Le formulaire gelé n'enregistre plus la pièce, mais la ventilation est un geste sur UNE ligne (spec § 7) :
    // le formulaire ne la bloque pas, c'est le service qui refuse la ligne rattachée, et le refus s'affiche.
    test('ventile la ligne sœur d\'une ligne rattachée', function () {
        ['libre' => $libre, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);

        Livewire::test(TransactionForm::class)->call('edit', (int) $piece->id)
            ->call('ouvrirVentilation', (int) $libre->id)
            ->call('saveVentilation')
            ->assertHasNoErrors();

        expect(DB::table('transaction_ligne_affectations')->where('transaction_ligne_id', $libre->id)->count())->toBe(1);
    });

    test('refuse la ligne rattachée, et affiche le refus dans le panneau de ventilation : rien n\'est écrit', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $avant = verrouEmpreinte($ligne, $provision);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id)
            ->call('ouvrirVentilation', (int) $ligne->id)
            ->call('saveVentilation');

        expect($formulaire->errors()->get('affectations'))->toBe([GelRattachement::MOTIF_PIECE_GELEE])
            ->and(verrouEmpreinte($ligne, $provision))->toBe($avant);
    });

    test('refuse la suppression de la ventilation de la ligne rattachée, avec le même refus affiché', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        verrouSemerAffectation($ligne->fresh());
        $avant = verrouEmpreinte($ligne, $provision);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $ligne->transaction_id)
            ->call('ouvrirVentilation', (int) $ligne->id)
            ->call('supprimerVentilation');

        expect($formulaire->errors()->get('affectations'))->toBe([GelRattachement::MOTIF_PIECE_GELEE])
            ->and(verrouEmpreinte($ligne, $provision))->toBe($avant);
    });
});

/**
 * Une pièce à une ligne (produit, sur une opération de trois séances), rattachée si $gelee. Le formulaire est ouvert
 * dessus, avec un règlement « reçu » pour que les champs de règlement soient rendus.
 */
function verrouFormulaireDeLaPiece(object $test, bool $gelee): array
{
    $operation = Operation::factory()->create(['nombre_seances' => 3, 'statut' => StatutOperation::EnCours]);
    $ligne = $test->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement, (int) $operation->id);

    if ($gelee) {
        verrouRattacherAvecEcritures($test, $ligne);
    }

    $formulaire = Livewire::test(TransactionForm::class)
        ->call('edit', (int) $ligne->transaction_id)
        ->set('paiementRecu', true);

    return [$formulaire, $ligne];
}

describe('TransactionForm : une pièce gelée s\'affiche en LECTURE SEULE, comme un exercice clôturé', function () {
    // Rien ne s'enregistrera sur une pièce gelée : le formulaire ne propose donc rien à saisir. Seuls restent offerts les
    // gestes sur UNE ligne (« Ventiler »), que le gel laisse ouverts sur les lignes sœurs (spec § 7). Ces tests lisent le
    // HTML rendu : c'est ce que l'utilisateur voit, et la seule façon de savoir ce qu'il peut atteindre.

    $champs = [
        'référence' => 'reference',
        'libellé' => 'libelle',
        'notes' => 'notes',
        'paiement reçu (oui / non)' => 'paiementRecu',
        'mode de paiement' => 'mode_paiement',
        'compte bancaire' => 'compte_id',
        'opération de la ligne' => 'lignes.0.operation_id',
        'séance de la ligne' => 'lignes.0.seance',
        'montant de la ligne' => 'lignes.0.montant',
        'notes de la ligne' => 'lignes.0.notes',
    ];

    test('désactive chaque champ de la pièce gelée, et aucun d\'une pièce libre', function (string $propriete) {
        [$gelee] = verrouFormulaireDeLaPiece($this, true);
        [$libre] = verrouFormulaireDeLaPiece($this, false);

        expect(verrouChampDesactive($gelee, $propriete))->toBeTrue()
            ->and(verrouChampDesactive($libre, $propriete))->toBeFalse();
    })->with($champs);

    test('rend les deux dates en lecture seule sur une pièce gelée, et choisissables sur une pièce libre', function () {
        [$gelee] = verrouFormulaireDeLaPiece($this, true);
        [$libre] = verrouFormulaireDeLaPiece($this, false);

        $dateReglement = Carbon::parse($gelee->get('dateReglement'))->format('d/m/Y');
        // Le champ rendu pour une date désactivée (composant x-date-input), sur plusieurs lignes.
        $champDate = fn (string $valeur): string => '/<input type="text"\s+value="'.preg_quote($valeur, '/').'"\s+class="form-control bg-light" disabled>/';

        // La date de la pièce (08/04/2026) et celle du règlement : des champs désactivés, non des sélecteurs de date.
        expect(preg_match($champDate('08/04/2026'), $gelee->html()))->toBe(1)
            ->and(preg_match($champDate($dateReglement), $gelee->html()))->toBe(1)
            ->and($gelee->html())->not->toContain('class="input-group" wire:ignore');
        expect(preg_match($champDate('08/04/2026'), $libre->html()))->toBe(0)
            ->and(preg_match($champDate($dateReglement), $libre->html()))->toBe(0)
            ->and(substr_count($libre->html(), 'class="input-group" wire:ignore'))->toBe(2);
    });

    test('ne propose ni le tiers ni le compte de la ligne à la saisie : leur sélecteur est remplacé par leur valeur', function () {
        [$gelee] = verrouFormulaireDeLaPiece($this, true);
        [$libre] = verrouFormulaireDeLaPiece($this, false);

        // Les sélecteurs sont des composants enfants : on lit leur présence dans le rendu.
        expect($gelee->html())->not->toContain('wire:name="tiers-autocomplete"')
            ->not->toContain('wire:name="compte-autocomplete"');
        expect($libre->html())->toContain('wire:name="tiers-autocomplete"')
            ->toContain('wire:name="compte-autocomplete"');

        // Le tiers porte le cadenas des champs verrouillés, la pièce libre non.
        $cadenasDuTiers = '/Tiers <span class="text-danger">\*<\/span>(?:\s|<!--\[if (?:END)?BLOCK\]><!\[endif\]-->)*<i class="bi bi-lock text-warning"/';
        expect(preg_match($cadenasDuTiers, $gelee->html()))->toBe(1)
            ->and(preg_match($cadenasDuTiers, $libre->html()))->toBe(0);
    });

    test('ne propose ni « Ajouter une ligne » ni le retrait d\'une ligne, alors qu\'une pièce libre les offre', function () {
        [$gelee] = verrouFormulaireDeLaPiece($this, true);
        [$libre] = verrouFormulaireDeLaPiece($this, false);

        $gelee->assertDontSee('Ajouter une ligne');
        expect($gelee->html())->not->toContain('wire:click="removeLigne(');

        $libre->assertSee('Ajouter une ligne');
        expect($libre->html())->toContain('wire:click="removeLigne(0)"');
    });

    test('propose « Ventiler » sur la ligne sœur de la pièce gelée, et pas sur la ligne rattachée : atteinte par le chemin de l\'écran, la sœur se ventile', function () {
        // $soeur est la ligne d'origine, libre ; $rattachee la ligne ajoutée, rattachée.
        ['libre' => $soeur, 'soeur' => $rattachee, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $piece->id);

        // Ce que l'écran offre : « Ventiler » sur la ligne libre seule. Sur la ligne rattachée, le service refuserait :
        // le bouton n'y est pas (le refus serveur reste testé au niveau du service et par un appel forgé, plus bas).
        expect(verrouLignesVentilables($formulaire))->toBe([(int) $soeur->id])
            ->and($formulaire->get('lignesGelees'))->toBe([(int) $rattachee->id]);

        // Le clic de l'écran : wire:click="ouvrirVentilation(<id>)", puis « Enregistrer » (saveVentilation).
        $formulaire->call('ouvrirVentilation', verrouLignesVentilables($formulaire)[0])
            ->call('saveVentilation')
            ->assertHasNoErrors();

        expect(DB::table('transaction_ligne_affectations')->where('transaction_ligne_id', $soeur->id)->count())->toBe(1);
    });

    test('n\'offre « Ventiler » sur aucune ligne d\'une écriture de rattachement, dotation ou extourne : toutes seraient refusées', function (string $ecriture) {
        ['piece' => $piece] = verrouEcritureDeRattachement($this, 7, $ecriture);
        $lignes = DB::table('transaction_lignes')->where('transaction_id', $piece->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $piece->id);

        // Garde anti-test-vide : la pièce a des lignes, et le formulaire en connaît le gel pour chacune.
        expect($lignes)->not->toBeEmpty()
            ->and($formulaire->get('lignesGelees'))->toBe($lignes)
            ->and(verrouLignesVentilables($formulaire))->toBe([]);
    })->with([
        'la dotation' => ['normale'],
        'l\'extourne' => ['extourne'],
    ]);

    test('affiche encore le refus du service quand un appel forgé ventile la ligne rattachée : rien n\'est écrit', function () {
        ['soeur' => $rattachee, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);
        $avant = verrouEmpreinte($rattachee, $provision);

        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $piece->id);
        // Le bouton n'est pas rendu sur cette ligne : l'appel ne vient pas de l'écran.
        expect(verrouLignesVentilables($formulaire))->not->toContain((int) $rattachee->id);

        $formulaire->call('ouvrirVentilation', (int) $rattachee->id)->call('saveVentilation');

        expect($formulaire->errors()->get('affectations'))->toBe([GelRattachement::MOTIF_PIECE_GELEE])
            ->and(verrouEmpreinte($rattachee, $provision))->toBe($avant);
    });

    test('laisse passer une ModelNotFoundException du service au lieu d\'afficher son texte technique, et affiche tout autre refus', function (string $action) {
        ['libre' => $libre, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);
        $formulaire = Livewire::test(TransactionForm::class)->call('edit', (int) $piece->id)
            ->call('ouvrirVentilation', (int) $libre->id);

        // TransactionService est final : un double sans héritage, qui ne sait faire que ces deux gestes.
        $double = fn (Throwable $echec): object => new class($echec)
        {
            public function __construct(private readonly Throwable $echec) {}

            public function affecterLigne(TransactionLigne $ligne, array $affectations): void
            {
                throw $this->echec;
            }

            public function supprimerAffectations(TransactionLigne $ligne): void
            {
                throw $this->echec;
            }
        };

        // Un refus métier (RuntimeException) s'affiche dans le panneau.
        app()->instance(TransactionService::class, $double(new RuntimeException('Refus de test')));
        $formulaire->call($action);
        expect($formulaire->errors()->get('affectations'))->toBe(['Refus de test']);

        // Une ligne ou une pièce qui n'existe plus n'est pas un message pour l'utilisateur : elle remonte.
        app()->instance(TransactionService::class, $double((new ModelNotFoundException)->setModel(TransactionLigne::class, [(int) $libre->id])));
        expect(fn () => $formulaire->call($action))->toThrow(ModelNotFoundException::class);
    })->with([
        'saveVentilation()' => ['saveVentilation'],
        'supprimerVentilation()' => ['supprimerVentilation'],
    ]);

    test('n\'offre « Ventiler » sur aucune ligne d\'une pièce libre : le gel n\'ajoute le bouton qu\'à la pièce gelée', function () {
        [$libre] = verrouFormulaireDeLaPiece($this, false);

        expect(verrouLignesVentilables($libre))->toBe([]);
    });

    test('garde le justificatif de la pièce consultable, sans proposer de le retirer, de le remplacer ni d\'en joindre un', function () {
        [$gelee, $ligne] = verrouFormulaireDeLaPiece($this, true);
        [$libre, $ligneLibre] = verrouFormulaireDeLaPiece($this, false);

        // Un justificatif existant sur chacune des deux pièces, au niveau de la pièce et de sa ligne.
        foreach ([$ligne, $ligneLibre] as $l) {
            DB::table('transactions')->where('id', $l->transaction_id)->update([
                'piece_jointe_path' => 'justificatif.pdf',
                'piece_jointe_nom' => 'facture-de-test.pdf',
                'piece_jointe_mime' => 'application/pdf',
            ]);
            DB::table('transaction_lignes')->where('id', $l->id)->update(['piece_jointe_path' => 'lignes/justificatif-ligne.pdf']);
        }
        $gelee->call('edit', (int) $ligne->transaction_id);
        $libre->call('edit', (int) $ligneLibre->transaction_id);

        // La pièce gelée : le nom du justificatif et le lien de consultation restent ; plus de suppression, de remplacement ni de dépôt.
        $gelee->assertSee('facture-de-test.pdf');
        expect($gelee->html())->toContain('title="Consulter"')
            ->not->toContain('wire:click="deletePieceJointe"')
            ->not->toContain('title="Remplacer"')
            ->not->toContain('lignes.0.piece_jointe_upload')
            ->not->toContain('piece_jointe_remove');

        // La pièce libre offre tout cela.
        expect($libre->html())->toContain('wire:click="deletePieceJointe"')
            ->toContain('title="Remplacer"')
            ->toContain('piece_jointe_remove');
    });

    // Un exercice clôturé, lui, ne masque ces contrôles que dans la vue : deletePieceJointe() n'y regarde que canEdit, et
    // le service ne regarde pas l'exercice (constaté, non corrigé ici). La pièce gelée, elle, est refusée côté serveur.
    test('deletePieceJointe() refuse côté serveur le justificatif d\'une pièce gelée : le fichier et la pièce sont intacts', function () {
        Storage::fake('local');
        [$gelee, $ligne] = verrouFormulaireDeLaPiece($this, true);
        [$libre, $ligneLibre] = verrouFormulaireDeLaPiece($this, false);

        $fichiers = [];
        foreach ([$ligne, $ligneLibre] as $l) {
            $piece = Transaction::findOrFail($l->transaction_id);
            $fichiers[$l->id] = $piece->storagePath('transactions/'.$piece->id).'/justificatif.pdf';
            Storage::disk('local')->put($fichiers[$l->id], 'contenu');
            DB::table('transactions')->where('id', $piece->id)->update([
                'piece_jointe_path' => 'justificatif.pdf',
                'piece_jointe_nom' => 'facture-de-test.pdf',
                'piece_jointe_mime' => 'application/pdf',
            ]);
        }
        $gelee->call('edit', (int) $ligne->transaction_id);
        $libre->call('edit', (int) $ligneLibre->transaction_id);
        $avant = (array) DB::table('transactions')->where('id', $ligne->transaction_id)->first();

        $gelee->call('deletePieceJointe');

        expect($gelee->errors()->get('pieceJointe'))->toBe([GelRattachement::MOTIF_PIECE_GELEE])
            ->and(Storage::disk('local')->exists($fichiers[$ligne->id]))->toBeTrue()
            ->and((array) DB::table('transactions')->where('id', $ligne->transaction_id)->first())->toBe($avant)
            ->and($gelee->get('existingPieceJointeNom'))->toBe('facture-de-test.pdf');

        // Garde anti-test-vide : sur une pièce libre, le même geste supprime bien le justificatif.
        $libre->call('deletePieceJointe')->assertHasNoErrors();
        expect(Storage::disk('local')->exists($fichiers[$ligneLibre->id]))->toBeFalse()
            ->and(DB::table('transactions')->where('id', $ligneLibre->transaction_id)->value('piece_jointe_path'))->toBeNull();
    });

    test('refuse côté serveur l\'envoi d\'un justificatif sur une pièce gelée : le fichier temporaire est abandonné, le justificatif existant est intact', function () {
        Storage::fake('local');
        [$gelee, $ligne] = verrouFormulaireDeLaPiece($this, true);
        [$libre] = verrouFormulaireDeLaPiece($this, false);

        $piece = Transaction::findOrFail($ligne->transaction_id);
        $fichier = $piece->storagePath('transactions/'.$piece->id).'/justificatif.pdf';
        Storage::disk('local')->put($fichier, 'contenu');
        DB::table('transactions')->where('id', $piece->id)->update(['piece_jointe_path' => 'justificatif.pdf', 'piece_jointe_nom' => 'facture-de-test.pdf']);
        $gelee->call('edit', (int) $piece->id);
        $avant = (array) DB::table('transactions')->where('id', $piece->id)->first();

        $gelee->set('pieceJointe', UploadedFile::fake()->create('remplacement.pdf', 20, 'application/pdf'));

        expect($gelee->errors()->get('pieceJointe'))->toBe([GelRattachement::MOTIF_PIECE_GELEE])
            ->and($gelee->get('pieceJointe'))->toBeNull()
            ->and(Storage::disk('local')->get($fichier))->toBe('contenu')
            ->and((array) DB::table('transactions')->where('id', $piece->id)->first())->toBe($avant);

        // Garde anti-test-vide : sur une pièce libre, le même envoi est accepté.
        $libre->set('pieceJointe', UploadedFile::fake()->create('nouveau.pdf', 20, 'application/pdf'))->assertHasNoErrors();
        expect($libre->get('pieceJointe'))->not->toBeNull();
    });

    test('n\'offre pas de dépôt de justificatif sur une pièce gelée qui n\'en a pas', function () {
        [$gelee] = verrouFormulaireDeLaPiece($this, true);
        [$libre] = verrouFormulaireDeLaPiece($this, false);

        $gelee->assertDontSee('Joindre un justificatif');
        expect($gelee->html())->not->toContain('wire:model="pieceJointe"')
            ->not->toContain('lignes.0.piece_jointe_upload');

        $libre->assertSee('Joindre un justificatif');
        expect($libre->html())->toContain('wire:model="pieceJointe"')
            ->toContain('lignes.0.piece_jointe_upload');
    });

    test('se titre « Visualiser » comme un exercice clôturé, la pièce libre « Modifier »', function () {
        [$gelee] = verrouFormulaireDeLaPiece($this, true);
        [$libre] = verrouFormulaireDeLaPiece($this, false);

        $gelee->assertSee('Visualiser la recette')->assertDontSee('Modifier la recette');
        $libre->assertSee('Modifier la recette')->assertDontSee('Visualiser la recette');
    });
});
