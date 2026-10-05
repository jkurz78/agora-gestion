<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Immobilisation;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\RattachementExerciceService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Verrou d'une pièce rattachée : édition, suppression, annulation (Task 8a)
|--------------------------------------------------------------------------
|
| Ces trois gestes de TransactionService réécrivent la pièce entière : update() détruit puis recrée
| toutes ses lignes, delete() et annuler() les suppriment logiquement avec elle. Une ligne rattachée
| gèle donc la pièce (spec § 7), par GelRattachement::verrouillerPieceNonGelee(), appelée à la place
| de l'ancien verrouillage de la pièce. Sans elle, update() butait sur la clé étrangère de `provisions`
| (une erreur SQL brute, AC-8), et delete() comme annuler() supprimaient sans broncher une pièce
| portant un rattachement. Les helpers (VERROU_MESSAGE, verrouGeste()…) vivent dans
| tests/Helpers/RattachementVerrou.php.
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

describe('TransactionService : update(), delete() et annuler() refusent une pièce rattachée', function () {
    test('refusent une pièce dont une ligne est rattachée, même si ce n\'est pas la première : le message exact, un refus métier et non une erreur SQL, et rien n\'a bougé', function (int $classe, string $geste) {
        ['libre' => $libre, 'soeur' => $soeur, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, $classe);

        // Garde anti-test-vide : c'est bien la sœur qui est rattachée, pas la ligne d'origine.
        expect($libre->estRattachee())->toBeFalse()
            ->and($soeur->estRattachee())->toBeTrue();

        verrouAssertRefuse(verrouGeste($geste, $piece), $soeur, $provision);
    })->with('rattachement_classes')->with('rattachement_gestes');

    test('refusent aussi quand la ligne rattachée est supprimée logiquement : le gel ne dépend pas de ce qu\'un filtre masque', function (int $classe, string $geste) {
        ['soeur' => $soeur, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, $classe);
        $soeur->delete();

        // Prémisse : la ligne est masquée par sa suppression logique, mais son rattachement tient.
        expect(TransactionLigne::find($soeur->id))->toBeNull()
            ->and(DB::table('provisions')->where('id', $provision->id)->exists())->toBeTrue();

        verrouAssertRefuse(verrouGeste($geste, $piece), $soeur, $provision);
    })->with('rattachement_classes')->with('rattachement_gestes');

    test('laissent passer une pièce libre, alors qu\'un rattachement existe sur une autre pièce : la garde regarde cette pièce', function (int $classe, string $geste) {
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree($classe));

        $libre = $this->ligneOrigineEquilibree($classe);
        $piece = Transaction::findOrFail($libre->transaction_id);

        verrouAssertPasse(verrouGeste($geste, $piece), $libre);
        verrouAssertEffet($geste, (int) $piece->id);
    })->with('rattachement_classes')->with('rattachement_gestes');

    test('laissent passer la pièce dont le rattachement a été retiré : la sortie annoncée par le refus fonctionne', function (int $classe, string $geste) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $piece = Transaction::findOrFail($ligne->transaction_id);

        // Rattachée, elle est refusée (garde anti-test-vide : c'est bien le retrait qui la libère).
        verrouAssertRefuse(verrouGeste($geste, $piece), $ligne, $provision);

        app(RattachementExerciceService::class)->retirer($provision);

        verrouAssertPasse(verrouGeste($geste, $piece), $ligne);
        verrouAssertEffet($geste, (int) $piece->id);
    })->with('rattachement_classes')->with('rattachement_gestes');

    test('verrouillent l\'exercice de la pièce, puis ses lots 401/411, la pièce, ses lignes et les rattachements : « for update » dans cet ordre, et la pièce UNE seule fois (MariaDB)', function (string $geste) {
        ['provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, 7);
        $action = verrouGeste($geste, $piece);

        // Le refus est jugé à l'intérieur de la transaction du geste : toutes ses requêtes sont relevées.
        $requetes = rattachementRequetesDeLaTransaction(
            fn () => expect($action)->toThrow(RuntimeException::class, VERROU_MESSAGE)
        );

        // Le même ordre aux trois sites, et celui de la garde : exercice, lots, pièce, lignes, rattachements.
        // Chaque verrou une seule fois : update() ne reprend pas la pièce que la garde a prise, et le refus
        // est la dernière requête, rien n'est lu ni écrit après.
        $indices = ['exercice' => rattachementIndiceDeLaPremiere($requetes, 'exercices', 2025)] + verrouIndicesDesVerrous($requetes);
        expect(array_keys($indices))->toBe(['exercice', 'liste des lots', 'lots', 'pièce', 'lignes', 'rattachements'])
            ->and(array_values($indices))->toBe(collect($indices)->sort()->values()->all())
            ->and(array_key_last($requetes))->toBe($indices['rattachements']);

        // L'exercice, lui, n'est lu qu'une fois (le verrou) ; aucune lecture de la pièce n'a précédé les lots.
        expect(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'exercices')))->toHaveCount(1);

        // SQLite n'écrit pas « for update » : seuls MySQL et MariaDB prouvent que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['exercice', 'lots', 'pièce', 'lignes', 'rattachements'] as $nom) {
                expect($requetes[$indices[$nom]]->sql)->toContain('for update');
            }
        }

        // Garde anti-test-vide : le rattachement qui a motivé le refus existe.
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeTrue();
    })->with('rattachement_gestes');

    test('refusent une écriture de rattachement (dotation ou extourne générée) : son propre message, et rien n\'a bougé', function (int $classe, string $geste, string $ecriture) {
        $ligne = $this->ligneOrigineEquilibree($classe);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $piece = Transaction::where('provision_id', (int) $provision->id)->where('type_ecriture', $ecriture)->sole();

        // Garde anti-test-vide : l'écriture visée porte bien le rattachement, et ce n'est pas la pièce d'origine.
        expect((int) $piece->provision_id)->toBe((int) $provision->id)
            ->and((int) $piece->id)->not->toBe((int) $ligne->transaction_id);

        verrouAssertRefuse(verrouGeste($geste, $piece), $ligne, $provision, VERROU_MESSAGE_ECRITURE);
    })->with('rattachement_classes')->with('rattachement_gestes')->with([
        'la dotation' => ['normale'],
        'l\'extourne' => ['extourne'],
    ]);

    test('refusent une écriture de rattachement même par une instance périmée qui ne porte plus son rattachement : la garde lit la pièce verrouillée', function (string $geste) {
        $ligne = $this->ligneOrigineEquilibree(7);
        $provision = verrouRattacherAvecEcritures($this, $ligne);
        $piece = Transaction::where('provision_id', (int) $provision->id)->where('type_ecriture', 'normale')->sole();

        // L'instance de l'appelant, lue avant : elle ne sait rien du rattachement. La base, elle, le porte.
        $piece->setRawAttributes(array_merge($piece->getAttributes(), ['provision_id' => null]), true);
        expect($piece->provision_id)->toBeNull()
            ->and((int) DB::table('transactions')->where('id', $piece->id)->value('provision_id'))->toBe((int) $provision->id);

        verrouAssertRefuse(verrouGeste($geste, $piece), $ligne, $provision, VERROU_MESSAGE_ECRITURE);
    })->with('rattachement_gestes');
});

describe('TransactionService : delete() et annuler() poursuivent sur la pièce que la garde rend', function () {
    // Les contrôles métier de delete() et d'annuler() (pièce pointée, remise, facture, immobilisation, et pour
    // annuler() déjà annulée) se font d'abord sur l'instance de l'appelant, hors transaction : un refus rapide.
    // Ils sont REFAITS sur la pièce que la garde rend, relue sous verrou : l'instance a pu être lue avant qu'un
    // autre utilisateur ne pointe, ne remette, ne facture ou n'annule la pièce. Le changement est posé juste après
    // la première requête de la transaction du geste (le verrou de l'exercice), avant les verrous de la pièce.
    test('refusent une pièce changée entre la lecture de l\'instance et le verrou : le message existant, et la pièce comme ses lignes n\'ont pas bougé', function (string $geste, string $cas, string $message) {
        $ligne = $this->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement);
        $piece = Transaction::findOrFail($ligne->transaction_id);

        $changement = match ($cas) {
            'annulée' => fn () => DB::table('transactions')->where('id', $piece->id)->update(['extournee_at' => now()]),
            'pointée' => fn () => DB::table('transactions')->where('id', $piece->id)->update(['rapprochement_id' => RapprochementBancaire::factory()->create()->id]),
            'remise' => fn () => DB::table('transactions')->where('id', $piece->id)->update(['remise_id' => RemiseBancaire::create([
                'association_id' => TenantContext::currentId(),
                'numero' => 1,
                'date' => '2026-04-09',
                'mode_paiement' => ModePaiement::Virement->value,
                'compte_cible_id' => CompteBancaire::factory()->create()->id,
                'libelle' => 'Remise test',
                'saisi_par' => $this->user->id,
            ])->id]),
            'facturée' => function () use ($piece): void {
                Facture::create([
                    'date' => '2026-04-09',
                    'statut' => StatutFacture::Validee,
                    'tiers_id' => Tiers::factory()->create()->id,
                    'montant_total' => 0,
                    'saisi_par' => auth()->id(),
                    'exercice' => 2025,
                ])->transactions()->attach($piece->id);
            },
            'immobilisation' => fn () => Immobilisation::factory()->create(['transaction_id' => $piece->id]),
        };

        // Garde anti-test-vide : l'instance de l'appelant ne sait rien du changement, et le geste passerait.
        expect($piece->extournee_at)->toBeNull()
            ->and($piece->rapprochement_id)->toBeNull()
            ->and($piece->remise_id)->toBeNull();

        // Le changement est posé UNE fois, avant le verrou de la pièce. Il vit dans la transaction du geste, que le refus
        // annule : l'état d'après n'est donc pas celui du changement. Seul le refus, et son message, le prouvent.
        verrouChangementApresLaPremiereRequeteDeLaTransaction($changement);

        expect(verrouMessageDuRefus(verrouGeste($geste, $piece)))->toBe($message);

        // La pièce n'est pas supprimée, ni ses lignes.
        expect(DB::table('transactions')->where('id', $piece->id)->value('deleted_at'))->toBeNull()
            ->and(DB::table('transaction_lignes')->where('transaction_id', $piece->id)->whereNotNull('deleted_at')->count())->toBe(0);
    })->with([
        'annuler() : déjà annulée' => ['annuler', 'annulée', 'Cette transaction a déjà été annulée.'],
        'annuler() : pointée' => ['annuler', 'pointée', 'Cette transaction est pointée dans un rapprochement et ne peut pas être annulée.'],
        'annuler() : en remise' => ['annuler', 'remise', 'Cette transaction est liée à une remise bancaire et ne peut pas être annulée.'],
        'annuler() : facturée' => ['annuler', 'facturée', 'Cette transaction est liée à une facture validée et ne peut pas être annulée.'],
        'annuler() : immobilisation' => ['annuler', 'immobilisation', 'Cette transaction provient d’une immobilisation et ne peut pas être annulée : supprimez la fiche.'],
        'delete() : pointée' => ['delete', 'pointée', 'Cette transaction est pointée dans un rapprochement et ne peut pas être supprimée.'],
        'delete() : en remise' => ['delete', 'remise', 'Cette transaction est liée à une remise bancaire et ne peut pas être supprimée.'],
        'delete() : facturée' => ['delete', 'facturée', 'Cette transaction est liée à une facture validée et ne peut pas être supprimée.'],
        'delete() : immobilisation' => ['delete', 'immobilisation', 'Cette transaction provient d’une immobilisation et ne peut pas être supprimée : supprimez la fiche.'],
    ]);

    test('poursuivent sur la pièce rendue, non sur l\'instance reçue : la pièce jointe ajoutée depuis la lecture de l\'instance est supprimée avec la pièce', function (string $geste) {
        Storage::fake('local');

        $ligne = $this->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement);
        $piece = Transaction::findOrFail($ligne->transaction_id);
        $fichier = $piece->storagePath('transactions/'.$piece->id).'/justificatif.pdf';
        Storage::disk('local')->put($fichier, 'contenu');

        // Une pièce jointe arrive après la lecture de l'instance, avant les verrous : seule la pièce relue la connaît.
        verrouChangementApresLaPremiereRequeteDeLaTransaction(fn () => DB::table('transactions')->where('id', $piece->id)->update([
            'piece_jointe_path' => 'justificatif.pdf',
            'piece_jointe_nom' => 'justificatif.pdf',
            'piece_jointe_mime' => 'application/pdf',
        ]));

        // Garde anti-test-vide : l'instance reçue ignore la pièce jointe, le fichier existe.
        expect($piece->hasPieceJointe())->toBeFalse();
        Storage::disk('local')->assertExists($fichier);

        verrouAssertPasse(verrouGeste($geste, $piece), $ligne);

        // Le fichier est parti avec la pièce, qui est supprimée logiquement, pièce jointe vidée.
        Storage::disk('local')->assertMissing($fichier);
        $apres = (array) DB::table('transactions')->where('id', $piece->id)->first();
        expect($apres['deleted_at'])->not->toBeNull()
            ->and($apres['piece_jointe_path'])->toBeNull();
    })->with([
        'delete()' => ['delete'],
        'annuler()' => ['annuler'],
    ]);
});

describe('TransactionService : delete() et annuler() écartent une pièce d\'une autre association avant la garde', function () {
    // Même défense en profondeur qu'update() : la garde du rattachement lève tous les scopes, elle verrouillerait
    // (et jugerait) une pièce que l'appelant n'a pas à connaître. Sans ce tri, delete() et annuler() supprimeraient
    // la pièce d'une autre association. Lu sur l'instance reçue, AVANT la garde et avant tout verrou de la pièce.
    test('refusent par une ModelNotFoundException une pièce d\'une autre association, et ne touchent à rien', function (string $geste) {
        $piece = Transaction::findOrFail($this->ligneOrigineEquilibree(7)->transaction_id);
        DB::table('transactions')->where('id', $piece->id)->update(['association_id' => Association::factory()->create()->id]);
        $piece->refresh();

        $avant = verrouEmpreinteDeLaPiece((int) $piece->id);

        expect(fn () => verrouGeste($geste, $piece)())
            ->toThrow(ModelNotFoundException::class);

        expect(verrouEmpreinteDeLaPiece((int) $piece->id))->toBe($avant);
    })->with([
        'delete()' => ['delete'],
        'annuler()' => ['annuler'],
    ]);

    test('ne verrouillent ni ne lisent la pièce d\'une autre association : le refus précède la garde', function (string $geste) {
        $piece = Transaction::findOrFail($this->ligneOrigineEquilibree(7)->transaction_id);
        DB::table('transactions')->where('id', $piece->id)->update(['association_id' => Association::factory()->create()->id]);
        $piece->refresh();

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => expect(fn () => verrouGeste($geste, $piece)())->toThrow(ModelNotFoundException::class)
        );

        // Les requêtes de la garde (lots, pièce, lignes, rattachements), en texte : une comparaison qui échoue
        // ne doit pas avoir à exporter les objets de requête.
        $deLaGarde = fn (array $requetes): array => array_values(array_filter(
            array_map(fn (object $r): string => $r->sql, $requetes),
            fn (string $sql): bool => preg_match('/from\s+[`"]?(transactions|transaction_lignes|provisions)[`"]?/i', $sql) === 1,
        ));
        expect($deLaGarde($requetes))->toBe([]);

        // Garde anti-test-vide : sur une pièce de l'association, les mêmes tables sont bien lues dans la transaction.
        $libre = Transaction::findOrFail($this->ligneOrigineEquilibree(7)->transaction_id);
        $requetes = rattachementRequetesDeLaTransaction(fn () => verrouGeste($geste, $libre)());
        expect($deLaGarde($requetes))->not->toBe([]);
    })->with([
        'delete()' => ['delete'],
        'annuler()' => ['annuler'],
    ]);
});

describe('TransactionService::update() : il poursuit sur la pièce que la garde rend', function () {
    // update() verrouille la pièce par la garde du rattachement, qui lit tous scopes levés (une pièce
    // supprimée porte encore ses rattachements). L'ancien verrouillage, sous scope, refusait par une
    // ModelNotFoundException la pièce supprimée entre-temps ou d'une autre association : update() garde
    // ce refus. Sans lui, il réécrirait les lignes d'une pièce supprimée.
    test('refuse par une ModelNotFoundException une pièce supprimée entre-temps ou d\'une autre association, et ne touche à rien', function (string $cas) {
        $ligne = $this->ligneOrigineEquilibree(7);
        $piece = Transaction::findOrFail($ligne->transaction_id);

        if ($cas === 'supprimée') {
            $piece->delete();
        } else {
            DB::table('transactions')->where('id', $piece->id)->update(['association_id' => Association::factory()->create()->id]);
            $piece->refresh();
        }

        $avant = verrouEmpreinteDeLaPiece((int) $piece->id);

        expect(fn () => verrouGeste('update', $piece)())
            ->toThrow(ModelNotFoundException::class);

        expect(verrouEmpreinteDeLaPiece((int) $piece->id))->toBe($avant);
    })->with([
        'pièce supprimée' => ['supprimée'],
        'pièce d\'une autre association' => ['autre association'],
    ]);

    // La moitié « autre association » du contrôle se lit sur l'instance reçue, AVANT la garde : celle-ci lève
    // tous les scopes, elle verrouillerait (et jugerait) une pièce que l'appelant n'a pas à connaître.
    test('ne verrouille ni ne lit la pièce d\'une autre association : le refus précède la garde', function () {
        $piece = Transaction::findOrFail($this->ligneOrigineEquilibree(7)->transaction_id);
        DB::table('transactions')->where('id', $piece->id)->update(['association_id' => Association::factory()->create()->id]);
        $piece->refresh();

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => expect(fn () => verrouGeste('update', $piece)())->toThrow(ModelNotFoundException::class)
        );

        // Les requêtes de la garde (lots, pièce, lignes, rattachements), en texte : une comparaison qui échoue
        // ne doit pas avoir à exporter les objets de requête.
        $deLaGarde = fn (array $requetes): array => array_values(array_filter(
            array_map(fn (object $r): string => $r->sql, $requetes),
            fn (string $sql): bool => preg_match('/from\s+[`"]?(transactions|transaction_lignes|provisions)[`"]?/i', $sql) === 1,
        ));
        expect($deLaGarde($requetes))->toBe([]);

        // Garde anti-test-vide : sur une pièce de l'association, les mêmes tables sont bien lues dans la transaction.
        $libre = Transaction::findOrFail($this->ligneOrigineEquilibree(7)->transaction_id);
        $requetes = rattachementRequetesDeLaTransaction(fn () => verrouGeste('update', $libre)());
        expect($deLaGarde($requetes))->not->toBe([]);
    });

    // Les contrôles qui suivent la garde (pièce pointée, réglée, facturée) lisent l'instance : celle de
    // l'appelant a pu être lue avant qu'un pointage ne l'atteigne. La garde rend la pièce relue sous
    // verrou, et update() poursuit avec elle, comme son ancien verrouillage le faisait.
    test('juge la pièce telle que la base la porte : un pointage arrivé depuis la lecture de l\'instance verrouille sa date', function () {
        $ligne = $this->ligneOrigineEquilibree(7);
        $piece = Transaction::findOrFail($ligne->transaction_id);

        DB::table('transactions')->where('id', $piece->id)->update(['statut_reglement' => 'pointe']);

        // Garde anti-test-vide : l'instance de l'appelant l'ignore, la base non.
        expect($piece->isLockedByRapprochement())->toBeFalse()
            ->and(Transaction::findOrFail($piece->id)->isLockedByRapprochement())->toBeTrue();

        [$donnees, $lignes] = verrouDonneesDEdition($piece);
        $donnees['date'] = '2026-04-09';

        expect(fn () => app(TransactionService::class)->update($piece, $donnees, $lignes))
            ->toThrow(RuntimeException::class, 'La date ne peut pas être modifiée sur une transaction rapprochée.');

        expect(Transaction::findOrFail($piece->id)->date->toDateString())->toBe('2026-04-08');
    });
});

describe('TransactionService::updateExtourneMiroir() : l\'extourne d\'un rattachement ne s\'y réécrit pas', function () {
    // Défense en profondeur : TransactionForm::save() refuse déjà une pièce gelée (refuserSiGelee()) avant d'appeler
    // ce service, mais le service ne doit pas s'en remettre à son seul appelant. La seule pièce de type extourne qui
    // puisse être gelée est celle d'un rattachement (`provision_id`) : une pièce d'annulation ne peut pas être
    // rattachée (RattachementExerciceService::motifAnnulation()).

    /** Les données d'édition d'un miroir : son libellé change, le reste est repris tel quel. */
    $donnees = fn (Transaction $piece): array => [
        'date' => $piece->date->toDateString(),
        'libelle' => 'Libellé corrigé',
        'mode_paiement' => $piece->mode_paiement,
        'compte_id' => $piece->compte_id,
        'notes' => null,
        'reference' => null,
    ];

    test('refuse une écriture de rattachement, dotation ou extourne : son propre message, et rien n\'a bougé', function (string $ecriture) use ($donnees) {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouEcritureDeRattachement($this, 7, $ecriture);

        verrouAssertRefuse(
            fn () => app(TransactionService::class)->updateExtourneMiroir($piece, $donnees($piece)),
            $ligne,
            $provision,
            VERROU_MESSAGE_ECRITURE,
        );
    })->with([
        'l\'extourne' => ['extourne'],
        'la dotation' => ['normale'],
    ]);

    test('refuse l\'extourne d\'un rattachement même par une instance périmée qui ne porte plus son rattachement : la garde lit la base', function () use ($donnees) {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouEcritureDeRattachement($this, 7, 'extourne');

        // L'instance de l'appelant, lue avant : elle ne sait rien du rattachement. La base, elle, le porte.
        $piece->setRawAttributes(array_merge($piece->getAttributes(), ['provision_id' => null]), true);
        expect($piece->provision_id)->toBeNull()
            ->and((int) DB::table('transactions')->where('id', $piece->id)->value('provision_id'))->toBe((int) $provision->id);

        verrouAssertRefuse(
            fn () => app(TransactionService::class)->updateExtourneMiroir($piece, $donnees($piece)),
            $ligne,
            $provision,
            VERROU_MESSAGE_ECRITURE,
        );
    });

    test('accepte le miroir d\'une annulation, même celle d\'une pièce rattachée : la garde ne vise que l\'extourne d\'un rattachement', function () use ($donnees) {
        ['piece' => $piece] = verrouPieceRattacheeAExtourner($this, 7);

        // Une annulation datée après N est permise (D13) : sa pièce miroir est de type extourne, sans rattachement.
        $miroir = verrouExtourner($piece, '2026-09-05')()->extourne;
        expect($miroir->type_ecriture)->toBe('extourne')
            ->and($miroir->provision_id)->toBeNull();

        // Le miroir est « pointé » dès sa création (remboursement par virement) : il en est détaché ici pour que
        // ce soit la garde du rattachement, et non le verrou du pointage, que ce test éprouve.
        DB::table('transactions')->where('id', $miroir->id)->update(['statut_reglement' => StatutReglement::EnAttente->value]);
        $miroir = $miroir->fresh();
        expect($miroir->isLockedByRapprochement())->toBeFalse();

        app(TransactionService::class)->updateExtourneMiroir($miroir, $donnees($miroir));

        expect($miroir->fresh()->libelle)->toBe('Libellé corrigé');
    });
});
