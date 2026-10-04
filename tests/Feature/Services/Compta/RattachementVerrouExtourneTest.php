<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Livewire\Extournes\AnnulerTransactionModal;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Provision;
use App\Models\Tiers;
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
use App\Services\FactureService;
use App\Services\TransactionExtourneService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Verrou d'une pièce rattachée à l'exercice : l'annulation par extourne (Task 8b)
|--------------------------------------------------------------------------
|
| Famille de sites du verrou (les autres : RattachementVerrou*Test), à part parce qu'elle seule dépend d'une DATE
| (spec § 7, D13) : l'annulation d'une pièce rattachée à N est refusée si elle est datée au plus tard
| le dernier jour de N (elle retirerait deux fois le même montant de N), permise après (elle est juste,
| et sans elle la pièce ne pourrait plus jamais être annulée une fois N clôturé). Une écriture de
| rattachement, elle, ne s'annule jamais ici, à aucune date. La garde vit au service,
| GelRattachement::verrouillerPieceExtournable(), que TransactionExtourneService::extourner()
| appelle dans sa transaction, avant de numéroter le miroir. Les helpers (verrouExtourner(),
| verrouAssertExtourneRefusee()…) vivent dans tests/Helpers/RattachementVerrou.php.
|
| Les pièces sont datées du 08/04/2026 : N est l'exercice 2025, qui finit le 31/08/2026.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486 et 487 pour les écritures de rattachement ; 411 et 401 pour les contreparties des pièces.
    SystemeSeeder::seed();
});

dataset('rattachement_dates_refusees', [
    'le dernier jour de N' => ['2026-08-31'],
    'dans N, après la pièce' => ['2026-04-09'],
    'avant N' => ['2025-08-31'],
]);

dataset('rattachement_dates_permises', [
    'le premier jour de N+1' => ['2026-09-01'],
    'plus tard dans N+1' => ['2027-03-15'],
    'dans N+2' => ['2027-09-01'],
]);

describe('TransactionExtourneService::extourner() : une pièce rattachée à N', function () {
    test('est refusée si l\'annulation est datée au plus tard le dernier jour de N, antérieure à N comprise : le message exact, et rien n\'a bougé', function (int $classe, string $date) {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouPieceRattacheeAExtourner($this, $classe);

        // Garde anti-test-vide : la pièce est bien rattachée, et l'annulation encore possible (non annulée).
        expect($ligne->estRattachee())->toBeTrue()
            ->and($piece->extournee_at)->toBeNull();

        verrouAssertExtourneRefusee(verrouExtourner($piece, $date), $ligne, $provision);

        // Ni annulation, ni pièce miroir : la pièce n'est pas marquée annulée.
        expect(Extourne::count())->toBe(0)
            ->and($piece->fresh()->extournee_at)->toBeNull();
    })->with('rattachement_classes')->with('rattachement_dates_refusees');

    test('passe si l\'annulation est datée après le dernier jour de N : la pièce miroir est créée à cette date, et le rattachement garde ses deux écritures', function (int $classe, string $date) {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouPieceRattacheeAExtourner($this, $classe);
        $avant = verrouEmpreinte($ligne, $provision);

        $extourne = verrouExtourner($piece, $date)();

        $miroir = $extourne->extourne;
        expect($extourne)->toBeInstanceOf(Extourne::class)
            ->and($miroir->date->toDateString())->toBe($date)
            ->and($miroir->type_ecriture)->toBe('extourne')
            ->and($miroir->provision_id)->toBeNull()
            ->and((int) $extourne->transaction_origine_id)->toBe((int) $piece->id)
            ->and($piece->fresh()->extournee_at)->not->toBeNull();

        // Le rattachement n'a pas bougé : sa ligne, ses deux écritures et leurs quatre lignes.
        $apres = verrouEmpreinte($ligne, $provision);
        expect($apres['provision'])->toBe($avant['provision'])
            ->and($apres['ecritures'])->toBe($avant['ecritures'])
            ->and($apres['lignes'])->toBe($avant['lignes'])
            ->and($apres['ecritures'])->toHaveCount(2)
            ->and($apres['lignes'])->toHaveCount(4);
    })->with('rattachement_classes')->with('rattachement_dates_permises');

    test('est jugée sur la date de l\'annulation, non sur celle de la pièce ni sur celle du jour', function () {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouPieceRattacheeAExtourner($this, 7);

        // « Aujourd'hui » tombe après N, la pièce est datée dans N : seule la date saisie pour l'annulation décide.
        $this->travelTo('2026-10-05');
        expect(now()->toDateString())->toBe('2026-10-05');

        verrouAssertExtourneRefusee(verrouExtourner($piece, '2026-08-31'), $ligne, $provision);

        $this->travelTo('2026-05-05');
        expect(verrouExtourner($piece, '2026-09-01')()->extourne->date->toDateString())->toBe('2026-09-01');
    });

    test('est permise à toute date une fois le rattachement retiré : la sortie annoncée par le refus fonctionne', function (int $classe, string $date) {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouPieceRattacheeAExtourner($this, $classe);

        // Rattachée, elle est refusée (garde anti-test-vide : c'est bien le retrait qui la libère).
        verrouAssertExtourneRefusee(verrouExtourner($piece, $date), $ligne, $provision);

        app(RattachementExerciceService::class)->retirer($provision);

        expect(verrouExtourner($piece, $date)()->extourne->date->toDateString())->toBe($date)
            ->and($piece->fresh()->extournee_at)->not->toBeNull();
    })->with('rattachement_classes')->with('rattachement_dates_refusees');

    test('est permise à toute date si elle est libre, alors qu\'un rattachement existe sur une autre pièce : la garde regarde cette pièce', function (int $classe, string $date) {
        verrouRattacherAvecEcritures($this, $this->ligneOrigineEquilibree($classe));

        $libre = $this->ligneOrigineEquilibree($classe, 175.0, ModePaiement::Virement);
        $piece = Transaction::findOrFail($libre->transaction_id);

        expect($libre->estRattachee())->toBeFalse();

        expect(verrouExtourner($piece, $date)()->extourne->date->toDateString())->toBe($date)
            ->and($piece->fresh()->extournee_at)->not->toBeNull();
    })->with('rattachement_classes')->with('rattachement_dates_refusees');

    test('est refusée par la ligne rattachée, même si ce n\'est pas la première ni celle de la pièce : le gel vaut pour la pièce entière', function (int $classe) {
        ['libre' => $libre, 'soeur' => $soeur, 'provision' => $provision, 'piece' => $piece] = verrouPieceDontLaSoeurEstRattachee($this, $classe);
        expect($libre->estRattachee())->toBeFalse()
            ->and($soeur->estRattachee())->toBeTrue();

        verrouAssertExtourneRefusee(verrouExtourner($piece, '2026-08-31'), $soeur, $provision);
    })->with('rattachement_classes');
});

describe('GelRattachement::verrouillerPieceExtournable()', function () {
    // Une pièce est dans un seul exercice, donc ses rattachements sont tous du même : ce cas n'arrive pas par les
    // écrans. La garde ne le suppose pourtant pas : elle refuse dès que l'annulation tombe dans l'un d'eux, c'est-à-dire
    // qu'elle compare la date à la fin du plus récent.
    test('juge une pièce portant des rattachements de plusieurs exercices sur le plus récent', function () {
        $premiere = $this->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement);
        $seconde = verrouSoeur(7, $premiere);

        foreach ([[$premiere, 2024], [$seconde, 2025]] as [$ligne, $exercice]) {
            Provision::create([
                'association_id' => TenantContext::currentId(),
                'exercice' => $exercice,
                'transaction_ligne_id' => $ligne->id,
                'montant' => max((float) $ligne->debit, (float) $ligne->credit),
                'saisi_par' => auth()->id(),
            ]);
        }

        $piece = Transaction::findOrFail($premiere->transaction_id);
        $service = app(GelRattachement::class);
        $garde = fn (string $date): ?string => verrouMessageDuRefus(
            fn () => DB::transaction(fn () => $service->verrouillerPieceExtournable($piece, Carbon::parse($date)))
        );

        // Après la fin du premier (31/08/2025), avant celle du second (31/08/2026) : refusée, au nom du second.
        expect($garde('2026-04-09'))->toBe(VERROU_MESSAGE_EXTOURNE_PRECOCE)
            ->and($garde('2026-08-31'))->toBe(VERROU_MESSAGE_EXTOURNE_PRECOCE)
            ->and($garde('2026-09-01'))->toBeNull();
    });

    test('rend la pièce relue sous verrou, et lève une ModelNotFoundException quand elle n\'existe plus', function () {
        $ligne = $this->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement);
        $piece = Transaction::findOrFail($ligne->transaction_id);
        $service = app(GelRattachement::class);

        // L'instance de l'appelant est périmée : la pièce rendue est celle de la base.
        DB::table('transactions')->where('id', $piece->id)->update(['libelle' => 'Libellé de la base']);
        $rendue = $service->verrouillerPieceExtournable($piece, Carbon::parse('2026-08-31'));

        expect((int) $rendue->id)->toBe((int) $piece->id)
            ->and($rendue->libelle)->toBe('Libellé de la base')
            ->and($rendue)->not->toBe($piece);

        // Une pièce qui n'existe plus (supprimée physiquement) ne se rend pas : un refus, pas un passage.
        DB::table('transaction_lignes')->where('transaction_id', $piece->id)->delete();
        DB::table('transactions')->where('id', $piece->id)->delete();

        expect(fn () => $service->verrouillerPieceExtournable($piece, Carbon::parse('2026-09-01')))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('TransactionExtourneService::extourner() : une écriture de rattachement ne s\'annule jamais', function () {
    test('refuse la dotation et l\'extourne générées, à toute date : son propre message, et rien n\'a bougé', function (int $classe, string $ecriture, string $date) {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouEcritureDeRattachement($this, $classe, $ecriture);

        // Garde anti-test-vide : la pièce visée est l'écriture, pas la pièce d'origine.
        expect((int) $piece->provision_id)->toBe((int) $provision->id)
            ->and((int) $piece->id)->not->toBe((int) $ligne->transaction_id);

        verrouAssertExtourneRefusee(verrouExtourner($piece, $date), $ligne, $provision, VERROU_MESSAGE_ECRITURE);
    })->with('rattachement_classes')->with([
        'la dotation' => ['normale'],
        'l\'extourne' => ['extourne'],
    ])->with([
        // La date qui permettrait d'annuler la pièce d'origine, et une qui ne le permettrait pas : la
        // raison du refus n'est pas la date.
        'datée après N' => ['2026-09-15'],
        'datée dans N' => ['2026-08-31'],
    ]);

    test('refuse une écriture de rattachement même par une instance périmée qui ne porte plus son rattachement : la garde lit la pièce verrouillée', function () {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouEcritureDeRattachement($this, 7, 'normale');

        $piece->setRawAttributes(array_merge($piece->getAttributes(), ['provision_id' => null]), true);
        expect($piece->provision_id)->toBeNull()
            ->and((int) DB::table('transactions')->where('id', $piece->id)->value('provision_id'))->toBe((int) $provision->id);

        verrouAssertExtourneRefusee(verrouExtourner($piece, '2026-09-15'), $ligne, $provision, VERROU_MESSAGE_ECRITURE);
    });
});

describe('TransactionExtourneService::extourner() : l\'ordre des verrous', function () {
    test('refus : l\'exercice de l\'annulation, les lots 401/411, la pièce, ses lignes, les rattachements — et rien après, la numérotation n\'est jamais atteinte (MariaDB)', function () {
        ['provision' => $provision, 'piece' => $piece] = verrouPieceRattacheeAExtourner($this, 7);

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => expect(verrouExtourner($piece, '2026-08-31'))->toThrow(RuntimeException::class, VERROU_MESSAGE_EXTOURNE_PRECOCE)
        );

        // L'exercice est celui de l'ANNULATION (31/08/2026 : 2025), lu une seule fois ; chaque verrou de la garde
        // une seule fois, la pièce comprise ; le refus est la dernière requête.
        $indices = ['exercice' => rattachementIndiceDeLaPremiere($requetes, 'exercices', 2025)] + verrouIndicesDesVerrous($requetes);
        expect(array_keys($indices))->toBe(['exercice', 'liste des lots', 'lots', 'pièce', 'lignes', 'rattachements'])
            ->and(array_values($indices))->toBe(collect($indices)->sort()->values()->all())
            ->and(array_key_last($requetes))->toBe($indices['rattachements'])
            ->and(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'exercices')))->toHaveCount(1)
            ->and(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'sequences')))->toBeEmpty();

        // SQLite n'écrit pas « for update » : seuls MySQL et MariaDB prouvent que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['exercice', 'lots', 'pièce', 'lignes', 'rattachements'] as $nom) {
                expect($requetes[$indices[$nom]]->sql)->toContain('for update');
            }
        }

        // Garde anti-test-vide : le rattachement qui a motivé le refus existe.
        expect(DB::table('provisions')->where('id', $provision->id)->exists())->toBeTrue();
    });

    test('passage : les mêmes verrous, dans le même ordre, puis la numérotation du miroir — la pièce n\'est verrouillée qu\'UNE fois (MariaDB)', function () {
        ['piece' => $piece] = verrouPieceRattacheeAExtourner($this, 7);

        $requetes = rattachementRequetesDeLaTransaction(verrouExtourner($piece, '2026-09-01'));

        // L'exercice est celui de l'annulation (01/09/2026 : 2026), pas celui de la pièce (2025).
        $indices = ['exercice' => rattachementIndiceDeLaPremiere($requetes, 'exercices', 2026)] + verrouIndicesDesVerrous($requetes);

        // La numérotation : la première requête sur `sequences` (l'insertion qui garantit la ligne), puis son verrou.
        $numerotation = array_keys(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'sequences')));
        expect($numerotation)->not->toBeEmpty();
        $indices['numérotation'] = $numerotation[0];

        expect(array_keys($indices))->toBe(['exercice', 'liste des lots', 'lots', 'pièce', 'lignes', 'rattachements', 'numérotation'])
            ->and(array_values($indices))->toBe(collect($indices)->sort()->values()->all());

        if (DB::getDriverName() !== 'sqlite') {
            foreach (['exercice', 'lots', 'pièce', 'lignes', 'rattachements'] as $nom) {
                expect($requetes[$indices[$nom]]->sql)->toContain('for update');
            }

            // Le verrou de la numérotation : sa lecture « for update », après la garde.
            $verrouNumero = array_keys(array_filter($requetes, fn (object $r): bool => str_contains($r->sql, 'sequences') && str_contains($r->sql, 'for update')));
            expect($verrouNumero)->not->toBeEmpty()
                ->and($verrouNumero[0])->toBeGreaterThan($indices['rattachements']);
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

describe('TransactionExtourneService::extourner() : il rejuge la pièce sous verrou', function () {
    // L'instance de l'appelant a été lue avant d'ouvrir la transaction : un autre utilisateur a pu changer la
    // pièce depuis. Le changement est posé juste après la première requête de la transaction (le verrou de
    // l'exercice), c'est-à-dire avant les verrous de la pièce : la garde le voit, l'instance de l'appelant non.
    test('refuse une pièce annulée, supprimée ou venue de HelloAsso entre la lecture de l\'instance et le verrou : le message existant, et pas de miroir', function (string $cas, string $message) {
        // Une pièce libre : le refus ne vient pas du rattachement. Ce test éprouve la relecture, pas la date.
        $libre = $this->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement);
        $piece = Transaction::findOrFail($libre->transaction_id);

        $changements = [
            'annulée' => ['extournee_at' => now()],
            'supprimée' => ['deleted_at' => now()],
            'HelloAsso' => ['helloasso_order_id' => 4242],
        ];

        $avant = verrouEmpreinteDeLaPiece((int) $piece->id);
        $compteurs = [DB::table('transactions')->count(), DB::table('extournes')->count()];

        // Garde anti-test-vide : l'instance de l'appelant ne sait rien du changement, et l'extourne passerait.
        expect($piece->extournee_at)->toBeNull()
            ->and($piece->trashed())->toBeFalse()
            ->and($piece->helloasso_order_id)->toBeNull();

        verrouChangementApresLaPremiereRequeteDeLaTransaction(
            fn () => DB::table('transactions')->where('id', $piece->id)->update($changements[$cas])
        );

        expect(verrouMessageDuRefus(verrouExtourner($piece, '2026-09-15')))->toBe($message);

        // Aucune pièce miroir, aucune annulation : seul le changement, posé par le test, a eu lieu.
        expect([DB::table('transactions')->count(), DB::table('extournes')->count()])->toBe($compteurs)
            ->and(DB::table('transaction_lignes')->where('transaction_id', $piece->id)->count())->toBe(count($avant['lignes']));
    })->with([
        'annulée entre-temps' => ['annulée', 'Cette transaction a déjà été annulée.'],
        'supprimée entre-temps' => ['supprimée', 'Cette transaction a été supprimée et ne peut pas être annulée.'],
        'venue de HelloAsso entre-temps' => ['HelloAsso', 'Les transactions issues de HelloAsso ne peuvent pas être annulées manuellement.'],
    ]);
});

// ── Les deux appelants : la fenêtre d'annulation choisit la date, l'annulation d'une facture l'impose ──────────
//
// Le refus d'une annulation trop tôt nomme sa sortie (spec § 7). La fenêtre d'annulation saisit sa date : « datez
// l'annulation après N » est une sortie. FactureService::annuler() extourne ses pièces À LA DATE DU JOUR, que personne ne
// saisit : le même conseil n'y a pas de sortie, et le refus dit d'attendre le premier jour de N+1. La différence
// vient d'ExtournePayload::$dateImposee, que fromOrigine() pose quand aucune date n'est donnée.

/**
 * Une facture validée qui porte la pièce rattachée par une ligne manuelle : FactureService::annuler() l'extourne d'office.
 */
function verrouFactureValideePortant(Transaction $piece, TransactionLigne $ligne): Facture
{
    $facture = Facture::create([
        'date' => '2026-01-10',
        'statut' => StatutFacture::Validee,
        'tiers_id' => Tiers::factory()->create()->id,
        'montant_total' => 175,
        'saisi_par' => auth()->id(),
        'exercice' => 2025,
    ]);
    $facture->transactions()->attach($piece->id);
    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Stage',
        'prix_unitaire' => 175,
        'quantite' => 1,
        'montant' => 175,
        'transaction_ligne_id' => $ligne->id,
        'compte_id' => $ligne->compte_id,
        'ordre' => 1,
    ]);

    return $facture->fresh();
}

describe('ExtournePayload::fromOrigine() : la date est-elle imposée ?', function () {
    test('l\'est quand aucune date n\'est donnée, ne l\'est pas dès qu\'une date l\'est, même celle du jour', function () {
        $piece = Transaction::findOrFail($this->ligneOrigineEquilibree(7, 175.0, ModePaiement::Virement)->transaction_id);

        expect(ExtournePayload::fromOrigine($piece)->dateImposee)->toBeTrue()
            ->and(ExtournePayload::fromOrigine($piece, ['date' => null])->dateImposee)->toBeTrue()
            ->and(ExtournePayload::fromOrigine($piece, ['date' => now()->toDateString()])->dateImposee)->toBeFalse()
            ->and(ExtournePayload::fromOrigine($piece, ['date' => '2026-09-01'])->dateImposee)->toBeFalse();
    });
});

describe('FactureService::annuler() : une pièce rattachée à N, annulée aujourd\'hui', function () {
    test('est refusée tant qu\'aujourd\'hui tombe dans N : le motif sans « Datez », la facture reste validée, rien n\'a bougé', function (int $classe) {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouPieceRattacheeAExtourner($this, $classe);
        $facture = verrouFactureValideePortant($piece, $ligne);

        // Garde anti-test-vide : aujourd'hui (15/01/2026, figé) est dans N, la facture est validée.
        expect(now()->toDateString())->toBe('2026-01-15')
            ->and($facture->statut)->toBe(StatutFacture::Validee)
            ->and($facture->transactionsGenereesParLignesManuelles()->pluck('id')->all())->toBe([(int) $piece->id]);

        verrouAssertExtourneRefusee(
            fn () => app(FactureService::class)->annuler($facture),
            $ligne,
            $provision,
            VERROU_MESSAGE_EXTOURNE_DATE_IMPOSEE,
        );

        // Le refus ne propose pas une sortie que ce parcours n'a pas.
        expect(VERROU_MESSAGE_EXTOURNE_DATE_IMPOSEE)->not->toContain('Datez');

        // La facture est restée validée, sans avoir ni date d'annulation : le refus annule tout.
        $apres = $facture->fresh();
        expect($apres->statut)->toBe(StatutFacture::Validee)
            ->and($apres->numero_avoir)->toBeNull()
            ->and($apres->date_annulation)->toBeNull()
            ->and($piece->fresh()->extournee_at)->toBeNull()
            ->and(Extourne::count())->toBe(0);
    })->with('rattachement_classes');

    test('passe quand aujourd\'hui est après N : la facture est annulée, la pièce miroir est datée du jour', function () {
        ['ligne' => $ligne, 'provision' => $provision, 'piece' => $piece] = verrouPieceRattacheeAExtourner($this, 7);
        $facture = verrouFactureValideePortant($piece, $ligne);
        $avant = verrouEmpreinte($ligne, $provision);

        $this->travelTo('2026-09-15');
        app(FactureService::class)->annuler($facture);

        expect($facture->fresh()->statut)->toBe(StatutFacture::Annulee)
            ->and(Extourne::count())->toBe(1)
            ->and(Extourne::sole()->extourne->date->toDateString())->toBe('2026-09-15')
            ->and($piece->fresh()->extournee_at)->not->toBeNull();

        // Le rattachement n'a pas bougé.
        $apres = verrouEmpreinte($ligne, $provision);
        expect($apres['provision'])->toBe($avant['provision'])
            ->and($apres['ecritures'])->toBe($avant['ecritures']);
    });
});

describe('AnnulerTransactionModal : la fenêtre d\'annulation choisit sa date, elle garde le motif qui propose de la changer', function () {
    test('refuse avec « Datez l\'annulation après le 31/08/2026 », même quand la date saisie est celle du jour', function () {
        ['piece' => $piece] = verrouPieceRattacheeAExtourner($this, 7);

        // La fenêtre ne propose l'annulation par extourne qu'une fois la pièce encaissée.
        DB::table('transactions')->where('id', $piece->id)->update(['statut_reglement' => StatutReglement::Recu->value]);

        // Prémisse : la date que la fenêtre pré-remplit est celle du jour, dans N.
        Livewire::test(AnnulerTransactionModal::class)
            ->call('open', (int) $piece->id)
            ->assertSet('mode', 'extourne')
            ->assertSet('date', '2026-01-15')
            ->call('submit')
            ->assertSet('isOpen', true)
            ->assertSet('errorMessage', VERROU_MESSAGE_EXTOURNE_PRECOCE);

        expect(VERROU_MESSAGE_EXTOURNE_PRECOCE)->toContain('Datez l’annulation après le 31/08/2026')
            ->and(Extourne::count())->toBe(0);
    });
});
