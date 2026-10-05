<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Enums\StatutOperation;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\HelloAssoSyncResult;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Verrou d'une ligne rattachée : la synchronisation HelloAsso (Task 9, spec § 7)
|--------------------------------------------------------------------------
|
| La synchronisation ne REFUSE pas : elle tourne en tâche de fond, sur toutes les commandes d'un lot, et un
| refus ferait échouer la commande. Elle CONSERVE ce dont un rattachement dépend, le journalise (Log::warning :
| ce qui est conservé, ce que la commande annonçait) et continue. L'écriture de rattachement recopie le compte,
| l'opération et la séance de sa ligne d'origine (D3, D8) et son montant (D12) : sur une ligne rattachée, la
| synchronisation ne modifie donc NI le montant (montant, débit, crédit), NI le compte, NI l'opération, NI la
| pièce qui la porte (transaction_id) ; sur une pièce gelée, elle ne modifie pas la date (la redater la ferait
| changer d'exercice sous son rattachement). Elle synchronise tout le reste : les libellés, notes, palier et code
| promo ; la ligne sœur non rattachée ; les autres champs de l'en-tête ; les autres commandes du lot.
|
| Ces tests passent par le VRAI HelloAssoSyncService, sur des commandes simulées (aucun appel à l'API :
| Http::preventStrayRequests()). Un test par conservation tue celle-là et pas une autre : retirer la
| conservation du compte ne fait tomber que le test du compte.
|
| Les gardes elles-mêmes (ligneGeleeSousVerrou(), verrouillerPieceSansRefus()) : RattachementGelGardesTest.php.
*/

const SYNCHRO_MESSAGE_LIGNE = '[HelloAsso] ligne rattachée à l’exercice suivant : valeurs comptables conservées';
const SYNCHRO_MESSAGE_DATE = '[HelloAsso] pièce gelée par un rattachement à l’exercice suivant : date conservée';

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486/487 (régularisation), 411 : les comptes que le rattachement et la conversion partie double utilisent.
    SystemeSeeder::seed();

    // Aucun appel réel à l'API HelloAsso, quoi qu'il arrive.
    Http::fake();
    Http::preventStrayRequests();

    $assocId = (int) TenantContext::currentId();
    $banque = CompteBancaire::factory()->create();

    $this->compteDon = Compte::factory()->recette()->create(['association_id' => $assocId, 'actif' => true]);
    $this->autreCompteDon = Compte::factory()->recette()->create(['association_id' => $assocId, 'actif' => true]);
    $this->operation = Operation::factory()->create(['statut' => StatutOperation::EnCours]);
    $this->autreOperation = Operation::factory()->create(['statut' => StatutOperation::EnCours]);

    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => $assocId,
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $banque->id,
        'compte_versement_id' => $banque->id,
        // Un don additionnel dans un formulaire d'adhésion se ventile sur ce compte de repli.
        'compte_don_id' => $this->compteDon->id,
    ]);

    // Le formulaire porte l'opération : « changer l'opération côté mapping » en change l'opération de la ligne.
    $this->mapping = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'adhesion-saison',
        'form_type' => 'Membership',
        'form_title' => 'Adhésion saison',
        'compte_id' => $this->compteDon->id,
        'operation_id' => $this->operation->id,
    ]);

    $this->aurore = Tiers::factory()->create(['helloasso_nom' => 'DUPONT', 'helloasso_prenom' => 'Aurore', 'est_helloasso' => true]);
    $this->bob = Tiers::factory()->create(['helloasso_nom' => 'MARTIN', 'helloasso_prenom' => 'Bob', 'est_helloasso' => true]);
});

/**
 * Une commande HelloAsso simulée : un don additionnel (item 95001, 175 €) et son option « Repas » (12 €), au nom
 * d'Aurore, le 15/10/2025 (exercice 2025). Chaque paramètre surcharge un défaut ; `option => null` retire l'option.
 *
 * @param  array<string, mixed>  $surcharges
 * @return array<string, mixed>
 */
function synchroCommande(array $surcharges = []): array
{
    $c = $surcharges + [
        'id' => 90001, 'item' => 95001, 'date' => '2025-10-15T10:00:00Z', 'montant' => 17500,
        'option' => 1200, 'nomOption' => 'Repas', 'prenom' => 'Aurore', 'nom' => 'DUPONT',
        'moyen' => 'Card', 'formType' => 'Membership',
    ];
    $total = $c['montant'] + ($c['option'] ?? 0);

    $item = [
        'id' => $c['item'],
        'amount' => $c['montant'],
        'initialAmount' => $c['montant'],
        'type' => 'Donation',
        'name' => 'Don additionnel',
        'user' => ['firstName' => $c['prenom'], 'lastName' => $c['nom']],
    ];

    if ($c['option'] !== null) {
        $item['options'] = [['optionId' => 111, 'name' => $c['nomOption'], 'amount' => $c['option']]];
    }

    return [
        'id' => $c['id'],
        'date' => $c['date'],
        'formSlug' => 'adhesion-saison',
        'formType' => $c['formType'],
        'payments' => [['id' => $c['id'] - 20000, 'amount' => $total, 'paymentMeans' => $c['moyen']]],
        'amount' => ['total' => $total, 'discount' => 0, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Aurore', 'lastName' => 'DUPONT'],
        'items' => [$item],
    ];
}

/** Une passe de la synchronisation sur ces commandes, avec les paramètres relus (le mapping et le compte de repli peuvent avoir changé). */
function synchroLancer(TestCase $test, array ...$commandes): HelloAssoSyncResult
{
    return (new HelloAssoSyncService($test->parametres->fresh()))->synchroniser($commandes, 2025);
}

/**
 * Rattache la ligne HelloAsso $cle de l'item $item (« parent », « option:111 »…) : le rattachement et ses deux écritures.
 *
 * @return array{0: TransactionLigne, 1: Provision}
 */
function synchroRattacher(TestCase $test, string $cle = 'parent', int $item = 95001): array
{
    $ligne = TransactionLigne::where('helloasso_item_id', $item)->where('helloasso_line_key', $cle)->sole();

    return [$ligne, verrouRattacherAvecEcritures($test, $ligne)];
}

/** Ce qu'une synchronisation n'a pas le droit de toucher : le rattachement, ses deux écritures et leurs quatre lignes. */
function synchroEmpreinteDuRattachement(TransactionLigne $ligne, Provision $provision): array
{
    $empreinte = Arr::only(verrouEmpreinte($ligne, $provision), ['provision', 'ecritures', 'lignes', 'affectations']);

    // Garde anti-test-vide : le rattachement existe, avec ses deux écritures et leurs quatre lignes.
    expect($empreinte['provision'])->not->toBeEmpty()
        ->and($empreinte['ecritures'])->toHaveCount(2)
        ->and($empreinte['lignes'])->toHaveCount(4);

    return $empreinte;
}

/** La pièce de la commande 90001 d'un tiers, relue en base (tous ses champs). */
function synchroPiece(Tiers $tiers, int $commande = 90001): Transaction
{
    return Transaction::where('helloasso_order_id', $commande)->where('tiers_id', $tiers->id)->sole();
}

/** Σ débit et Σ crédit de toutes les lignes de la pièce : sa partie double. */
function synchroEquilibre(int $pieceId): array
{
    return [
        'debit' => round((float) DB::table('transaction_lignes')->where('transaction_id', $pieceId)->sum('debit'), 2),
        'credit' => round((float) DB::table('transaction_lignes')->where('transaction_id', $pieceId)->sum('credit'), 2),
    ];
}

/**
 * Le contexte du Log::warning d'une ligne rattachée dont la commande annonçait d'autres valeurs : EXACTEMENT les
 * champs conservés (ceux qui diffèrent), avec la valeur conservée et celle de la commande.
 *
 * @param  array<string, mixed>  $conserve
 * @param  array<string, mixed>  $recu
 */
function synchroAssertAvertissementDeLigne(object $spy, TransactionLigne $ligne, array $conserve, array $recu): void
{
    $spy->shouldHaveReceived('warning')
        ->with(SYNCHRO_MESSAGE_LIGNE, Mockery::on(function (array $contexte) use ($ligne, $conserve, $recu): bool {
            return (int) $contexte['ligne_id'] === (int) $ligne->id
                && (int) $contexte['helloasso_item_id'] === (int) $ligne->helloasso_item_id
                && $contexte['helloasso_line_key'] === $ligne->helloasso_line_key
                && $contexte['conserve'] == $conserve
                && $contexte['recu'] == $recu;
        }))
        ->once();
}

describe('Synchronisation HelloAsso : une ligne rattachée garde ce dont son rattachement dépend', function () {
    test('conserve le montant, le débit et le crédit : le total, l\'équilibre et le rattachement n\'ont pas bougé, les libellés se synchronisent, le refus est journalisé', function () {
        synchroLancer($this, synchroCommande());
        [$option, $provision] = synchroRattacher($this, 'option:111');
        $piece = synchroPiece($this->aurore);

        // Garde anti-test-vide : 175 € + 12 € = 187 €, la ligne rattachée est l'option de 12 € et porte sa note.
        expect((float) $piece->montant_total)->toBe(187.0)
            ->and((float) $option->credit)->toBe(12.0)
            ->and($option->notes)->toBe('Option : Repas')
            ->and(synchroEquilibre((int) $piece->id))->toBe(['debit' => 187.0, 'credit' => 187.0]);
        $rattachement = synchroEmpreinteDuRattachement($option, $provision);

        $spy = Log::spy();
        $resultat = synchroLancer($this, synchroCommande(['option' => 1500, 'nomOption' => 'Repas bio']));

        expect($resultat->errors)->toBe([]);
        $option->refresh();
        expect((float) $option->montant)->toBe(12.0)
            ->and((float) $option->debit)->toBe(0.0)
            ->and((float) $option->credit)->toBe(12.0)
            // « Les libellés restent synchronisés ».
            ->and($option->notes)->toBe('Option : Repas bio');

        // La pièce garde son total et sa partie double ; le rattachement et ses écritures n'ont pas bougé.
        expect((float) synchroPiece($this->aurore)->montant_total)->toBe(187.0)
            ->and(synchroEquilibre((int) $piece->id))->toBe(['debit' => 187.0, 'credit' => 187.0])
            ->and(synchroEmpreinteDuRattachement($option, $provision))->toBe($rattachement);

        synchroAssertAvertissementDeLigne($spy, $option, ['montant' => 12.0, 'credit' => 12.0], ['montant' => 15.0, 'credit' => 15.0]);
    });

    test('conserve le débit et le crédit tels que la base les porte, quel que soit leur sens', function () {
        synchroLancer($this, synchroCommande(['option' => null]));
        [$parent, $provision] = synchroRattacher($this);

        // Une ligne rattachée est toujours au crédit (classe 7) : le sens inverse n'arrive pas par la synchronisation, mais
        // la conservation ne s'en remet pas à cette hypothèse. La ligne est posée au débit, hors de tout service.
        DB::table('transaction_lignes')->where('id', $parent->id)->update(['debit' => 175.0, 'credit' => 0.0]);

        $spy = Log::spy();
        synchroLancer($this, synchroCommande(['option' => null, 'montant' => 18000]));

        $parent->refresh();
        expect((float) $parent->debit)->toBe(175.0)
            ->and((float) $parent->credit)->toBe(0.0)
            ->and((float) $parent->montant)->toBe(175.0);

        synchroAssertAvertissementDeLigne(
            $spy,
            $parent,
            ['montant' => 175.0, 'debit' => 175.0, 'credit' => 0.0],
            ['montant' => 180.0, 'debit' => 0.0, 'credit' => 180.0],
        );
    });

    test('conserve le compte, quand la configuration du formulaire en désigne un autre', function () {
        synchroLancer($this, synchroCommande());
        [$parent, $provision] = synchroRattacher($this);
        $rattachement = synchroEmpreinteDuRattachement($parent, $provision);

        // Le compte de repli des dons additionnels change : une ligne non rattachée le suivrait (voir la pièce libre, plus bas).
        $this->parametres->update(['compte_don_id' => $this->autreCompteDon->id]);

        $spy = Log::spy();
        $resultat = synchroLancer($this, synchroCommande());

        expect($resultat->errors)->toBe([])
            ->and((int) $parent->fresh()->compte_id)->toBe((int) $this->compteDon->id)
            ->and(synchroEmpreinteDuRattachement($parent, $provision))->toBe($rattachement);

        synchroAssertAvertissementDeLigne($spy, $parent, ['compte_id' => (int) $this->compteDon->id], ['compte_id' => (int) $this->autreCompteDon->id]);
    });

    test('conserve l\'opération, quand la configuration du formulaire en désigne une autre', function () {
        synchroLancer($this, synchroCommande());
        [$parent, $provision] = synchroRattacher($this);
        $rattachement = synchroEmpreinteDuRattachement($parent, $provision);
        expect((int) $parent->operation_id)->toBe((int) $this->operation->id);

        $this->mapping->update(['operation_id' => $this->autreOperation->id]);

        $spy = Log::spy();
        $resultat = synchroLancer($this, synchroCommande());

        expect($resultat->errors)->toBe([])
            ->and((int) $parent->fresh()->operation_id)->toBe((int) $this->operation->id)
            ->and(synchroEmpreinteDuRattachement($parent, $provision))->toBe($rattachement);

        synchroAssertAvertissementDeLigne($spy, $parent, ['operation_id' => (int) $this->operation->id], ['operation_id' => (int) $this->autreOperation->id]);
    });

    test('ne déplace pas la ligne vers une autre pièce : l\'item passe à un autre bénéficiaire, la ligne reste dans sa pièce', function () {
        synchroLancer($this, synchroCommande(['option' => null]));
        [$parent, $provision] = synchroRattacher($this);
        $pieceDAurore = synchroPiece($this->aurore);
        $rattachement = synchroEmpreinteDuRattachement($parent, $provision);

        // Le bénéficiaire de l'item change : la commande se range désormais sous le tiers Bob, donc dans une autre pièce.
        $spy = Log::spy();
        $resultat = synchroLancer($this, synchroCommande(['option' => null, 'prenom' => 'Bob', 'nom' => 'MARTIN']));

        // Garde anti-test-vide : la commande a bien été rangée sous une autre pièce, celle de Bob.
        $pieceDeBob = synchroPiece($this->bob);
        expect((int) $pieceDeBob->id)->not->toBe((int) $pieceDAurore->id)
            ->and($resultat->errors)->toBe([]);

        expect((int) $parent->fresh()->transaction_id)->toBe((int) $pieceDAurore->id)
            ->and((float) $pieceDAurore->fresh()->montant_total)->toBe(175.0)
            ->and(synchroEmpreinteDuRattachement($parent, $provision))->toBe($rattachement);

        synchroAssertAvertissementDeLigne($spy, $parent, ['transaction_id' => (int) $pieceDAurore->id], ['transaction_id' => (int) $pieceDeBob->id]);
    });

    test('synchronise la ligne SŒUR d\'une ligne rattachée : seule la ligne rattachée est conservée', function () {
        synchroLancer($this, synchroCommande());
        [$option, $provision] = synchroRattacher($this, 'option:111');
        $rattachement = synchroEmpreinteDuRattachement($option, $provision);

        // Le don passe de 175 à 180 € et l'option de 12 à 15 € : la sœur (le don) suit, la rattachée (l'option) non.
        synchroLancer($this, synchroCommande(['montant' => 18000, 'option' => 1500]));

        $don = TransactionLigne::where('helloasso_item_id', 95001)->where('helloasso_line_key', 'parent')->sole();
        expect((float) $don->montant)->toBe(180.0)
            ->and((float) $don->credit)->toBe(180.0)
            ->and((float) $option->fresh()->credit)->toBe(12.0)
            // Le total de la pièce est la somme des lignes HelloAsso, la rattachée à son montant conservé.
            ->and((float) synchroPiece($this->aurore)->montant_total)->toBe(192.0)
            ->and(synchroEmpreinteDuRattachement($option, $provision))->toBe($rattachement);
    });
});

describe('Synchronisation HelloAsso : les lignes d\'une écriture de rattachement', function () {
    test('ne portent aucune clé HelloAsso : la synchronisation, qui retrouve ses lignes par elles, ne les rencontre jamais', function () {
        synchroLancer($this, synchroCommande(['option' => null]));
        [$parent, $provision] = synchroRattacher($this);

        $ecritures = Transaction::where('provision_id', $provision->id)->pluck('id');
        $lignes = DB::table('transaction_lignes')->whereIn('transaction_id', $ecritures)->get();

        // Garde anti-test-vide : les deux écritures ont leurs quatre lignes, et la ligne HelloAsso d'origine garde sa clé.
        expect($ecritures)->toHaveCount(2)
            ->and($lignes)->toHaveCount(4)
            ->and($lignes->whereNotNull('helloasso_item_id')->count())->toBe(0)
            ->and($lignes->whereNotNull('helloasso_line_key')->count())->toBe(0)
            ->and((int) $parent->fresh()->helloasso_item_id)->toBe(95001);

        // Et un nouveau passage ne touche à aucune d'elles.
        $avant = $lignes->map(fn (object $l): array => (array) $l)->all();
        synchroLancer($this, synchroCommande(['option' => null, 'montant' => 18000]));
        expect(DB::table('transaction_lignes')->whereIn('transaction_id', $ecritures)->get()->map(fn (object $l): array => (array) $l)->all())->toBe($avant);
    });
});

describe('Synchronisation HelloAsso : la date d\'une pièce gelée', function () {
    test('conserve la date d\'une pièce dont une ligne est rattachée, journalise, et synchronise les autres champs de l\'en-tête', function () {
        synchroLancer($this, synchroCommande());
        [$parent, $provision] = synchroRattacher($this);
        $piece = synchroPiece($this->aurore);
        $rattachement = synchroEmpreinteDuRattachement($parent, $provision);
        expect($piece->date->toDateString())->toBe('2025-10-15');

        // Redatée dans l'exercice SUIVANT : le rattachement de l'exercice 2025 n'aurait plus de sens. Libellé et mode de
        // paiement changent aussi : eux se synchronisent.
        $spy = Log::spy();
        $resultat = synchroLancer($this, synchroCommande(['date' => '2026-09-10T10:00:00Z', 'formType' => 'Donation', 'moyen' => 'Check']));

        expect($resultat->errors)->toBe([]);
        $apres = synchroPiece($this->aurore);
        expect($apres->date->toDateString())->toBe('2025-10-15')
            ->and($apres->libelle)->toBe('HelloAsso — Donation (adhesion-saison)')
            ->and($apres->mode_paiement->value)->toBe('cheque')
            ->and(synchroEmpreinteDuRattachement($parent, $provision))->toBe($rattachement);

        $spy->shouldHaveReceived('warning')
            ->with(SYNCHRO_MESSAGE_DATE, Mockery::on(fn (array $contexte): bool => (int) $contexte['transaction_id'] === (int) $piece->id
                && (int) $contexte['helloasso_order_id'] === 90001
                && $contexte['conserve'] === '2025-10-15'
                && $contexte['recu'] === '2026-09-10'))
            ->once();
    });

    test('met à jour la date d\'une pièce libre, sans rien journaliser', function () {
        synchroLancer($this, synchroCommande());

        $spy = Log::spy();
        synchroLancer($this, synchroCommande(['date' => '2026-09-10T10:00:00Z']));

        expect(synchroPiece($this->aurore)->date->toDateString())->toBe('2026-09-10');
        $spy->shouldNotHaveReceived('warning');
    });
});

describe('Synchronisation HelloAsso : une pièce libre se synchronise comme avant', function () {
    test('met à jour le montant, le compte, l\'opération et la date, et ne journalise rien', function () {
        synchroLancer($this, synchroCommande(['option' => null]));
        $parent = TransactionLigne::where('helloasso_item_id', 95001)->sole();

        $this->parametres->update(['compte_don_id' => $this->autreCompteDon->id]);
        $this->mapping->update(['operation_id' => $this->autreOperation->id]);

        $spy = Log::spy();
        $resultat = synchroLancer($this, synchroCommande(['option' => null, 'montant' => 18000, 'date' => '2026-09-10T10:00:00Z']));

        expect($resultat->errors)->toBe([]);
        $parent->refresh();
        expect((float) $parent->montant)->toBe(180.0)
            ->and((float) $parent->credit)->toBe(180.0)
            ->and((int) $parent->compte_id)->toBe((int) $this->autreCompteDon->id)
            ->and((int) $parent->operation_id)->toBe((int) $this->autreOperation->id)
            ->and((float) synchroPiece($this->aurore)->montant_total)->toBe(180.0)
            ->and(synchroPiece($this->aurore)->date->toDateString())->toBe('2026-09-10');
        $spy->shouldNotHaveReceived('warning');
    });

    test('déplace la ligne vers la pièce du nouveau bénéficiaire, comme avant', function () {
        synchroLancer($this, synchroCommande(['option' => null]));
        $parent = TransactionLigne::where('helloasso_item_id', 95001)->sole();

        $spy = Log::spy();
        synchroLancer($this, synchroCommande(['option' => null, 'prenom' => 'Bob', 'nom' => 'MARTIN']));

        expect((int) $parent->fresh()->transaction_id)->toBe((int) synchroPiece($this->bob)->id);
        $spy->shouldNotHaveReceived('warning');
    });

    test('ne journalise rien quand la commande n\'annonce rien de nouveau : la ligne rattachée et la pièce gelée passent sans bruit', function () {
        synchroLancer($this, synchroCommande());
        [$parent, $provision] = synchroRattacher($this);
        $avant = verrouEmpreinte($parent, $provision);

        $spy = Log::spy();
        $resultat = synchroLancer($this, synchroCommande());

        expect($resultat->errors)->toBe([])
            ->and(verrouEmpreinte($parent, $provision))->toBe($avant);
        $spy->shouldNotHaveReceived('warning');
    });
});

describe('Synchronisation HelloAsso : une conservation ne bloque jamais le lot', function () {
    test('synchronise normalement les autres commandes du même lot, sans erreur', function () {
        synchroLancer($this, synchroCommande(), synchroCommande(['id' => 90002, 'item' => 95002, 'prenom' => 'Bob', 'nom' => 'MARTIN', 'option' => null]));
        [$parent, $provision] = synchroRattacher($this);
        $rattachement = synchroEmpreinteDuRattachement($parent, $provision);

        // Les DEUX commandes changent de montant et de date : la première est conservée, la seconde (libre) suit.
        $resultat = synchroLancer(
            $this,
            synchroCommande(['montant' => 18000, 'date' => '2026-09-10T10:00:00Z']),
            synchroCommande(['id' => 90002, 'item' => 95002, 'prenom' => 'Bob', 'nom' => 'MARTIN', 'option' => null, 'montant' => 9000, 'date' => '2026-09-11T10:00:00Z']),
        );

        // Aucune exception ne remonte, aucune commande n'est écartée ni en erreur.
        expect($resultat->errors)->toBe([])
            ->and($resultat->ordersSkipped)->toBe(0)
            ->and($resultat->transactionsUpdated)->toBe(2);

        $conservee = TransactionLigne::where('helloasso_item_id', 95001)->where('helloasso_line_key', 'parent')->sole();
        $autre = TransactionLigne::where('helloasso_item_id', 95002)->sole();
        expect((float) $conservee->credit)->toBe(175.0)
            ->and(synchroPiece($this->aurore)->date->toDateString())->toBe('2025-10-15')
            ->and(synchroEmpreinteDuRattachement($parent, $provision))->toBe($rattachement)
            ->and((float) $autre->credit)->toBe(90.0)
            ->and((float) synchroPiece($this->bob, 90002)->montant_total)->toBe(90.0)
            ->and(synchroPiece($this->bob, 90002)->date->toDateString())->toBe('2026-09-11');
    });
});

describe('Synchronisation HelloAsso : l\'ordre des verrous', function () {
    /**
     * Pour une pièce existante, la synchronisation verrouille EN TÊTE de la transaction de la commande, dans l'ordre
     * canonique (les lots 401/411, la pièce, ses lignes, leurs rattachements : celui de update()), PUIS, pour chaque
     * ligne existante, la ligne et ses rattachements. rattacher() prend la pièce, puis la ligne, puis les rattachements :
     * la synchronisation qui prendrait la ligne AVANT la pièce (la pièce n'est écrite qu'à la fin, pour son total)
     * s'inter-bloquerait avec lui. Aucune écriture ne précède le dernier de ces verrous.
     */
    $motifs = [
        'liste des lots' => '/^select\s+[`"]id[`"],\s*[`"]poste_tiers_parent_id[`"]\s+from\s+[`"]transaction_lignes[`"]/i',
        'lots' => '/from\s+[`"]transaction_lignes[`"]\s+where\s*\(\s*[`"]transaction_lignes[`"]\.[`"]id[`"]\s*=\s*\?\s+or\s+[`"]poste_tiers_parent_id[`"]\s*=\s*\?/i',
        'pièce' => '/^select\s+\*\s+from\s+[`"]transactions[`"]\s+where\s+[`"]transactions[`"]\.[`"]id[`"]\s*=\s*\?\s+limit\s+1/i',
        'lignes de la pièce' => '/^select\s+[`"]id[`"]\s+from\s+[`"]transaction_lignes[`"]\s+where\s+[`"]transaction_id[`"]\s*=\s*\?\s+order\s+by/i',
        'rattachements de la pièce' => '/from\s+[`"]provisions[`"]\s+where\s+[`"]transaction_ligne_id[`"]\s+in\s*\(/i',
        'ligne' => '/^select\s+[`"]id[`"]\s+from\s+[`"]transaction_lignes[`"]\s+where\s+[`"]transaction_lignes[`"]\.[`"]id[`"]\s*=\s*\?\s+limit\s+1/i',
        'rattachements de la ligne' => '/from\s+[`"]provisions[`"]\s+where\s+[`"]provisions[`"]\.[`"]transaction_ligne_id[`"]\s*=\s*\?/i',
    ];

    test('verrouille la pièce avant ses lignes, puis la ligne avant ses rattachements, et n\'écrit rien avant d\'avoir tout verrouillé', function () use ($motifs) {
        synchroLancer($this, synchroCommande());
        [$option] = synchroRattacher($this, 'option:111');
        $piece = synchroPiece($this->aurore);

        $requetes = rattachementRequetesDeLaTransaction(
            fn () => synchroLancer($this, synchroCommande(['option' => 1500, 'nomOption' => 'Repas bio']))
        );
        $inventaire = implode("\n", array_map(fn (object $r, int $i): string => "#{$i} {$r->sql}", $requetes, array_keys($requetes)));

        // Le premier de chaque verrou : dans cet ordre, et jamais manquant.
        $indices = [];
        foreach ($motifs as $nom => $motif) {
            $trouvees = array_keys(array_filter($requetes, fn (object $r): bool => preg_match($motif, $r->sql) === 1));
            expect($trouvees)->not->toBeEmpty("« {$nom} » n'est jamais lu. Requêtes :\n{$inventaire}");
            $indices[$nom] = $trouvees[0];
        }

        expect(array_keys($indices))->toBe(array_keys($motifs))
            ->and(array_values($indices))->toBe(collect($indices)->sort()->values()->all(), "Ordre des verrous inattendu. Requêtes :\n{$inventaire}");

        // La pièce se lit par son identifiant ; la ligne rattachée de cette commande, elle aussi, est verrouillée avec ses
        // rattachements (le don, sa sœur, l'est aussi : on cherche celle-là parmi les verrous de ligne).
        $bindingsDesLignes = fn (string $nom): array => collect($requetes)
            ->filter(fn (object $r): bool => preg_match($motifs[$nom], $r->sql) === 1)
            ->flatMap(fn (object $r): array => $r->bindings)
            ->all();
        expect($requetes[$indices['pièce']]->bindings)->toContain((int) $piece->id)
            ->and($bindingsDesLignes('ligne'))->toContain((int) $option->id)
            ->and($bindingsDesLignes('rattachements de la ligne'))->toContain((int) $option->id);

        // Aucune écriture (ni de la pièce, ni d'une ligne) ne précède le verrou de la pièce, et le verrou de la pièce
        // précède TOUT verrou de ligne.
        $premiereEcriture = collect($requetes)->search(fn (object $r): bool => preg_match('/^\s*(insert|update|delete)\b/i', $r->sql) === 1);
        expect($premiereEcriture)->toBeInt()
            ->and($premiereEcriture)->toBeGreaterThan($indices['rattachements de la ligne'], "Une écriture précède un verrou. Requêtes :\n{$inventaire}")
            ->and($indices['pièce'])->toBeLessThan($indices['ligne']);

        // Ni la pièce, ni ses lignes, ni leurs rattachements ne portent de scope : seule la liste des lots se lit sous le scope tenant.
        foreach (['pièce', 'lignes de la pièce', 'rattachements de la pièce', 'ligne', 'rattachements de la ligne'] as $nom) {
            verrouAssertSansScope($requetes[$indices[$nom]]);
        }

        // SQLite n'écrit pas « for update » : seuls MySQL et MariaDB prouvent que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['lots', 'pièce', 'lignes de la pièce', 'rattachements de la pièce', 'ligne', 'rattachements de la ligne'] as $nom) {
                expect($requetes[$indices[$nom]]->sql)->toContain('for update');
            }
            expect($requetes[$indices['liste des lots']]->sql)->not->toContain('for update');
        }
    });

    test('décide du gel DANS la transaction de la commande, sur l\'état verrouillé : un rattachement posé après sa première lecture est vu', function () {
        synchroLancer($this, synchroCommande());
        $parent = TransactionLigne::where('helloasso_item_id', 95001)->where('helloasso_line_key', 'parent')->sole();
        expect($parent->estRattachee())->toBeFalse();

        // Juste après la première requête de la transaction de la commande (la lecture de sa pièce), un autre utilisateur
        // rattache la ligne. La synchronisation a lu la pièce et la ligne libres : elle doit relire sous verrou.
        $provision = null;
        verrouChangementApresLaPremiereRequeteDeLaTransaction(function () use (&$provision, $parent): void {
            $provision = verrouRattacherAvecEcritures($this, $parent);
        });

        $resultat = synchroLancer($this, synchroCommande(['montant' => 18000, 'date' => '2026-09-10T10:00:00Z']));

        // Garde anti-test-vide : le rattachement a bien été posé pendant la synchronisation.
        expect($provision)->toBeInstanceOf(Provision::class)
            ->and($resultat->errors)->toBe([]);

        expect((float) $parent->fresh()->credit)->toBe(175.0)
            ->and(synchroPiece($this->aurore)->date->toDateString())->toBe('2025-10-15');
    });
});
