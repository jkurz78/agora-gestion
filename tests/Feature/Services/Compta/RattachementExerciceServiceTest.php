<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\CasProvision;
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Exceptions\ExerciceCloturedException;
use App\Livewire\OperationDetail;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Extourne;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\ProvisionPDService;
use App\Services\Compta\RattachementExerciceService;
use App\Services\TransactionExtourneService;
use App\Services\TransactionUniverselleService;
use App\Tenant\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

dataset('rattachement_pca_cca', [
    'PCA' => [7, 175.0],
    'CCA' => [6, 90.0],
]);

dataset('rattachement_pca_cca_contrepartie', [
    'PCA' => [7, '487'],
    'CCA' => [6, '486'],
]);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // 486 et 487 : les comptes de régularisation, posés par le seeder système.
    SystemeSeeder::seed();
});

/**
 * Le rattachement de l'exercice 2025 (sept. 2025 → août 2026) d'une ligne d'origine.
 * Aucun libellé : il est hérité de la ligne d'origine.
 */
function provisionPourLigne(TransactionLigne $ligne, int $exercice = 2025): Provision
{
    return Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => $exercice,
        'transaction_ligne_id' => $ligne->id,
        'montant' => max((float) $ligne->debit, (float) $ligne->credit),
        'saisi_par' => auth()->id(),
    ]);
}

/**
 * Les lignes d'une écriture, indexées par numéro de compte.
 *
 * Le compte d'origine est fabriqué par la factory, avec un numéro unique : on
 * le désigne par `$ligne->compte->numero_pcg`, jamais par un numéro écrit en dur.
 *
 * @return Collection<string, TransactionLigne>
 */
function lignesParCompte(Transaction $ecriture): Collection
{
    return $ecriture->lignes->keyBy(fn (TransactionLigne $l) => $l->compte->numero_pcg);
}

/**
 * Les quatre chiffres qu'affiche l'écran de l'opération.
 *
 * @return array{totalRecettes: float, totalDepenses: float, totalDons: float, solde: float}
 */
function totauxOperation(Operation $operation): array
{
    $composant = Livewire::test(OperationDetail::class, ['operation' => $operation]);

    return [
        'totalRecettes' => $composant->viewData('totalRecettes'),
        'totalDepenses' => $composant->viewData('totalDepenses'),
        'totalDons' => $composant->viewData('totalDons'),
        'solde' => $composant->viewData('solde'),
    ];
}

/**
 * Impact d'une écriture sur le RÉSULTAT, signé : positif quand elle l'augmente.
 *
 * Seules les classes 6 et 7 comptent — c'est tout ce que lit le compte de
 * résultat. Un produit (classe 7) augmente le résultat au crédit, une charge
 * (classe 6) le diminue au débit. Un compte de classe 4 ne pèse rien : c'est
 * l'erreur de la spec de juin 2026, qui débitait 487 en croyant retirer un produit.
 */
function impactSurLeResultat(Transaction $ecriture): float
{
    $impact = 0.0;

    foreach ($ecriture->lignes as $ligne) {
        $classe = (int) $ligne->compte->classe;

        if ($classe === 7) {
            $impact += (float) $ligne->credit - (float) $ligne->debit;
        } elseif ($classe === 6) {
            $impact -= (float) $ligne->debit - (float) $ligne->credit;
        }
    }

    return round($impact, 2);
}

// ── Dotation : dernier jour de N ─────────────────────────────────────────────

test('PCA : dotation 7xx D / 487 C au dernier jour de N, en journal OD, rattachée à son rattachement', function () {
    $ligne = $this->ligneOrigine(7, 175.0, operationId: null);
    $provision = provisionPourLigne($ligne);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation($provision);

    expect($dotation->date->format('Y-m-d'))->toBe('2026-08-31');
    expect($dotation->journal)->toBe(JournalComptable::Od);
    expect($dotation->type_ecriture)->toBe('normale');
    expect((int) $dotation->provision_id)->toBe((int) $provision->id);
    expect($dotation->lignes)->toHaveCount(2);

    $lignes = lignesParCompte($dotation);

    expect((float) $lignes[$ligne->compte->numero_pcg]->debit)->toBe(175.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->credit)->toBe(0.0);
    expect((float) $lignes['487']->credit)->toBe(175.0);
    expect((float) $lignes['487']->debit)->toBe(0.0);
});

test('CCA : dotation 486 D / 6xx C', function () {
    $ligne = $this->ligneOrigine(6, 90.0);
    $provision = provisionPourLigne($ligne);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation($provision);
    $lignes = lignesParCompte($dotation);

    expect($dotation->lignes)->toHaveCount(2);
    expect((float) $lignes['486']->debit)->toBe(90.0);
    expect((float) $lignes['486']->credit)->toBe(0.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->credit)->toBe(90.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->debit)->toBe(0.0);
});

// ── Extourne : premier jour de N+1 ───────────────────────────────────────────

test('PCA : l’extourne est le miroir exact, 487 D / 7xx C, au premier jour de N+1', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $extourne = app(EcritureGenerator::class)->pourProvisionExtourne($provision);

    expect($extourne->date->format('Y-m-d'))->toBe('2026-09-01');
    expect($extourne->type_ecriture)->toBe('extourne');
    expect($extourne->journal)->toBe(JournalComptable::Od);
    expect((int) $extourne->provision_id)->toBe((int) $provision->id);

    $lignes = lignesParCompte($extourne);
    expect((float) $lignes['487']->debit)->toBe(175.0);
    expect((float) $lignes['487']->credit)->toBe(0.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->credit)->toBe(175.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->debit)->toBe(0.0);
});

test('CCA : l’extourne est le miroir exact, 6xx D / 486 C, au premier jour de N+1', function () {
    $ligne = $this->ligneOrigine(6, 90.0);
    $provision = provisionPourLigne($ligne);

    $extourne = app(EcritureGenerator::class)->pourProvisionExtourne($provision);

    expect($extourne->date->format('Y-m-d'))->toBe('2026-09-01');

    $lignes = lignesParCompte($extourne);
    expect((float) $lignes[$ligne->compte->numero_pcg]->debit)->toBe(90.0);
    expect((float) $lignes[$ligne->compte->numero_pcg]->credit)->toBe(0.0);
    expect((float) $lignes['486']->credit)->toBe(90.0);
    expect((float) $lignes['486']->debit)->toBe(0.0);
});

// ── Le sens de l'impact sur le résultat ──────────────────────────────────────
//
// L'erreur de la spec de juin 2026 a survécu parce qu'aucun test n'affirmait CE QUE
// L'ÉCRITURE FAIT AU RÉSULTAT, seulement quels comptes elle touchait. Ces tests le
// disent en euros, par cas, dotation puis extourne. (Le schéma inversé date du
// 18 juin 2026 ; l'écran existait depuis avril sans jamais servir : l'erreur n'a
// produit aucune écriture fausse en production.)

test('l’impact sur le résultat : la dotation corrige N, l’extourne rend à N+1, et la somme est nulle', function (int $classe, float $montant, float $impactDotation) {
    $ligne = $this->ligneOrigine($classe, $montant);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);
    $dotation = $generateur->pourProvisionDotation($provision);
    $extourne = $generateur->pourProvisionExtourne($provision);

    // PCA (produit de N rattaché à N+1) : le résultat de N BAISSE du montant.
    // CCA (charge de N rattachée à N+1) : le résultat de N MONTE du montant.
    expect(impactSurLeResultat($dotation))->toBe($impactDotation);
    expect(impactSurLeResultat($extourne))->toBe(-$impactDotation);
    expect(impactSurLeResultat($dotation) + impactSurLeResultat($extourne))->toBe(0.0);
})->with([
    'PCA : un produit de 175 € retiré de N' => [7, 175.0, -175.0],
    'CCA : une charge de 90 € retirée de N' => [6, 90.0, 90.0],
]);

test('le compte de résultat n’est touché que par la ligne de l’origine ; la contrepartie est de classe 4', function (int $classe, string $contrepartie) {
    $ligne = $this->ligneOrigine($classe, 120.0);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);

    foreach ([$generateur->pourProvisionDotation($provision), $generateur->pourProvisionExtourne($provision)] as $ecriture) {
        $classes = $ecriture->lignes->map(fn (TransactionLigne $l) => (int) $l->compte->classe)->sort()->values()->all();

        expect($classes)->toBe([4, $classe]);

        $comptes = $ecriture->lignes->map(fn (TransactionLigne $l) => (string) $l->compte->numero_pcg)->sort()->values()->all();

        expect($comptes)->toBe(collect([$contrepartie, (string) $ligne->compte->numero_pcg])->sort()->values()->all());
    }
})->with('rattachement_pca_cca_contrepartie');

// ── D8 : la dimension analytique ─────────────────────────────────────────────

test('l’opération et la séance sont portées par la ligne 6/7 et PAS par la contrepartie de classe 4 (D8)', function (int $classe, string $contrepartie) {
    $operation = Operation::factory()->create([
        'association_id' => TenantContext::currentId(),
        'date_debut' => '2026-09-18',
        'date_fin' => '2027-06-18',
    ]);
    $ligne = $this->ligneOrigine($classe, 175.0, operationId: (int) $operation->id, seance: 3);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);

    foreach ([$generateur->pourProvisionDotation($provision), $generateur->pourProvisionExtourne($provision)] as $ecriture) {
        $lignes = lignesParCompte($ecriture);
        $ligneResultat = $lignes[$ligne->compte->numero_pcg];

        expect((int) $ligneResultat->operation_id)->toBe((int) $operation->id);
        expect((int) $ligneResultat->seance)->toBe(3);
        expect($lignes[$contrepartie]->operation_id)->toBeNull();
        expect($lignes[$contrepartie]->seance)->toBeNull();
    }
})->with('rattachement_pca_cca_contrepartie');

test('une origine sans opération ni séance donne des lignes sans opération ni séance', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation($provision);

    expect($dotation->lignes)->toHaveCount(2);
    foreach ($dotation->lignes as $l) {
        expect($l->operation_id)->toBeNull();
        expect($l->seance)->toBeNull();
    }
});

// ── D3 : le tiers de l'origine sert à l'affichage, il ne va pas sur l'écriture ──
//
// Poser `transactions.tiers_id` ferait apparaître cette écriture d'inventaire sur la
// fiche du tiers (TiersTransactionService ne filtre aucun journal) et dans ses totaux.
// LigneOrigineHelper ne pose aucun tiers : sans ce test, rien ne protégerait la règle
// que ne défend qu'un commentaire.

test('les écritures ne portent pas le tiers de l’origine, ni sur la transaction ni sur leurs lignes', function (int $classe, float $montant) {
    $tiers = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
    $ligne = $this->ligneOrigine($classe, $montant);
    Transaction::whereKey($ligne->transaction_id)->update(['tiers_id' => $tiers->id]);

    // La prémisse : l'origine porte bien un tiers.
    expect((int) Transaction::find($ligne->transaction_id)->tiers_id)->toBe((int) $tiers->id);

    $provision = provisionPourLigne($ligne);
    $generateur = app(EcritureGenerator::class);

    foreach ([$generateur->pourProvisionDotation($provision), $generateur->pourProvisionExtourne($provision)] as $ecriture) {
        expect($ecriture->tiers_id)->toBeNull();

        expect($ecriture->lignes)->toHaveCount(2);
        foreach ($ecriture->lignes as $l) {
            expect($l->tiers_id)->toBeNull();
        }
    }
})->with('rattachement_pca_cca');

// ── L'écran de l'opération ───────────────────────────────────────────────────
//
// Une écriture de rattachement n'est ni une recette ni une dépense de l'opération :
// c'est une écriture d'inventaire. Elle corrige son compte de résultat (le CR par
// opération la lit), mais OperationDetail ne doit afficher aucun de ses trois
// totaux modifié. Tout rattachement est typé « dépense » : ses deux écritures
// tombent du même côté et s'additionnent aux dépenses. Sans le filtre de journal,
// une PCA de 175 € encaissés donnait recettes 175 / dépenses 350 / solde −175, et
// une CCA de 90 € payés des dépenses de 270 au lieu de 90.

test('l’écran de l’opération n’affiche aucun total modifié par un rattachement', function (int $classe, float $montant, bool $compteDeDon, array $attendu) {
    $operation = Operation::factory()->create([
        'association_id' => TenantContext::currentId(),
        'date_debut' => '2026-09-18',
        'date_fin' => '2027-06-18',
    ]);
    $ligne = $this->ligneOrigine($classe, $montant, operationId: (int) $operation->id);

    if ($compteDeDon) {
        $compteDon = Compte::factory()->recette()->pourDons()->create(['association_id' => TenantContext::currentId()]);
        $ligne->update(['compte_id' => $compteDon->id]);
    }

    expect(totauxOperation($operation))->toBe($attendu);

    app(ProvisionPDService::class)->generer(provisionPourLigne($ligne));

    expect(totauxOperation($operation))->toBe($attendu);
})->with([
    'PCA : 175 € encaissés' => [7, 175.0, false, ['totalRecettes' => 175.0, 'totalDepenses' => 0.0, 'totalDons' => 0.0, 'solde' => 175.0]],
    'CCA : 90 € payés' => [6, 90.0, false, ['totalRecettes' => 0.0, 'totalDepenses' => 90.0, 'totalDons' => 0.0, 'solde' => -90.0]],
    'PCA sur un compte de don' => [7, 175.0, true, ['totalRecettes' => 0.0, 'totalDepenses' => 0.0, 'totalDons' => 175.0, 'solde' => 175.0]],
]);

// Un rattachement est typé « dépense » : il ne peut donc plus atteindre les filtres des
// recettes et des dons. La règle vaut pourtant quel que soit le type — la branche
// recette de la liste universelle inclut le journal OD, une OD typée recette n'est pas
// une hypothèse d'école. Ce test pose directement de telles écritures, ventilées sur
// l'opération, et exige que les trois totaux les ignorent. Chacun des trois filtres
// tombe si on le retire.

test('les trois totaux de l’écran de l’opération ignorent une écriture d’inventaire, quel que soit son type', function () {
    $assocId = (int) TenantContext::currentId();
    $operation = Operation::factory()->create([
        'association_id' => $assocId,
        'date_debut' => '2026-09-18',
        'date_fin' => '2027-06-18',
    ]);

    // Une vraie recette de l'opération : la référence que les OD ne doivent pas bouger.
    $this->ligneOrigine(7, 175.0, operationId: (int) $operation->id);
    $attendu = ['totalRecettes' => 175.0, 'totalDepenses' => 0.0, 'totalDons' => 0.0, 'solde' => 175.0];
    expect(totauxOperation($operation))->toBe($attendu);

    $compteRecette = Compte::factory()->recette()->create(['association_id' => $assocId]);
    $compteDon = Compte::factory()->recette()->pourDons()->create(['association_id' => $assocId]);
    $compteCharge = Compte::factory()->depense()->create(['association_id' => $assocId]);

    $ecritureOd = function (TypeTransaction $type, array $lignes) use ($assocId, $operation): void {
        $ecriture = Transaction::create([
            'association_id' => $assocId,
            'type' => $type,
            'date' => '2026-08-31',
            'libelle' => 'Écriture d’inventaire',
            'montant_total' => 100.0,
            'saisi_par' => auth()->id(),
            'equilibree' => true,
            'type_ecriture' => 'normale',
            'journal' => JournalComptable::Od,
        ]);

        foreach ($lignes as [$compte, $debit, $credit]) {
            TransactionLigne::create([
                'transaction_id' => $ecriture->id,
                'compte_id' => $compte->id,
                'debit' => $debit,
                'credit' => $credit,
                'montant' => max($debit, $credit),
                'operation_id' => $operation->id,
                'libelle' => 'Écriture d’inventaire',
            ]);
        }
    };

    // OD typée recette : une ligne de classe 7 au crédit et une ligne de don.
    $ecritureOd(TypeTransaction::Recette, [[$compteRecette, 0.0, 100.0], [$compteDon, 0.0, 40.0]]);
    // OD typée dépense : une ligne de classe 6 au débit.
    $ecritureOd(TypeTransaction::Depense, [[$compteCharge, 60.0, 0.0]]);

    expect(totauxOperation($operation))->toBe($attendu);
});

// ── compta:check-integrity ───────────────────────────────────────────────────
//
// Le contrôle quotidien compare montant_total au net débit/crédit des lignes 6/7.
// La dotation d'un rattachement retire un produit ou une charge : son montant_total
// est donc négatif, et celui de son extourne positif — à l'inverse d'une extourne
// ordinaire, qui nie le montant de sa pièce d'origine. C'est la commande réelle qui
// doit se taire — recalculer la règle ici à la main ne prouverait rien.

test('compta:check-integrity ne signale aucune écriture de rattachement', function (int $classe, float $montant) {
    $association = TenantContext::current();
    $ligne = $this->ligneOrigine($classe, $montant);
    app(ProvisionPDService::class)->generer(provisionPourLigne($ligne));

    $code = Artisan::call('compta:check-integrity');
    $texte = Artisan::output();

    // La commande réamorce le tenant à chaque association : on remet le nôtre.
    TenantContext::boot($association);

    expect($texte)->not->toContain('anomalie');
    expect($code)->toBe(0);
})->with('rattachement_pca_cca');

// ── Le type de transaction : une écriture d'inventaire n'est ni recette ni dépense ──
//
// Toutes les écritures de rattachement sont typées « dépense », comme les dotations
// d'amortissement : le type ne sert qu'à les tenir hors des écrans de travail. Typées
// selon leur impact sur le résultat (« recette » quand il monte), une sur deux
// apparaissait dans « Toutes les transactions » avec un badge et une couleur qui
// disaient l'inverse de ce qu'elle fait (« recette » en rouge sur −90 pour la
// dotation d'une CCA).

test('les deux écritures d’un rattachement sont typées dépense, pour une PCA comme pour une CCA', function (int $classe, float $montant) {
    $ligne = $this->ligneOrigine($classe, $montant);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);

    expect($generateur->pourProvisionDotation($provision)->type)->toBe(TypeTransaction::Depense);
    expect($generateur->pourProvisionExtourne($provision)->type)->toBe(TypeTransaction::Depense);
})->with('rattachement_pca_cca');

test('aucune écriture de rattachement n’apparaît dans la liste universelle des transactions', function (int $classe, float $montant) {
    $ligne = $this->ligneOrigine($classe, $montant);
    $provision = provisionPourLigne($ligne);
    app(ProvisionPDService::class)->generer($provision);

    $ecrituresRattachement = Transaction::where('provision_id', $provision->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($ecrituresRattachement)->toHaveCount(2);

    // Aucune borne de date : la liste porte toute la table `transactions`. (`exercice:`
    // ne filtre pas les dates des transactions, il ne sert qu'aux reports à nouveau :
    // on ne prétend donc pas couvrir un exercice.)
    $lignes = collect(app(TransactionUniverselleService::class)
        ->paginate(null, null, null, null, null, null, null, null, null, null, null, perPage: 100)['paginator']
        ->items());

    // La pièce d'origine, elle, est bien listée : la liste n'est pas vide par accident.
    expect($lignes->pluck('id')->map(fn ($id) => (int) $id)->all())->toContain((int) $ligne->transaction_id);

    // Les identifiants d'une union peuvent se recouper entre sources (virements,
    // remises) : on ne regarde que les lignes issues de `transactions`.
    $listees = $lignes->whereIn('source_type', ['recette', 'depense'])
        ->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect(array_intersect($ecrituresRattachement, $listees))->toBe([]);
})->with('rattachement_pca_cca');

// ── Gardes ───────────────────────────────────────────────────────────────────

test('l’écriture rendue porte déjà ses lignes et leurs comptes : aucune relecture', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);
    $provision->load('ligne.compte');

    DB::enableQueryLog();
    $dotation = app(EcritureGenerator::class)->pourProvisionDotation($provision);
    $requetes = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Les lignes viennent d'être créées en mémoire, avec leur compte : les relire
    // (`load('lignes.compte')`) coûterait deux requêtes par écriture, quatre par
    // `generer()`. Le rattachement a ses relations préchargées : toute lecture de
    // `transaction_lignes` pendant la génération serait cette relecture.
    $relectures = $requetes->filter(fn (string $sql) => str_starts_with(mb_strtolower($sql), 'select')
        && str_contains($sql, 'transaction_lignes'));

    expect($dotation->relationLoaded('lignes'))->toBeTrue();
    expect($dotation->lignes)->toHaveCount(2);
    expect($relectures->all())->toBe([]);
});

test('le libellé de l’écriture nomme le cas en toutes lettres et reprend le libellé de l’origine', function (int $classe, string $cas) {
    $ligne = $this->ligneOrigine($classe, 100.0);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);

    // Visible au journal OD et au grand livre : des mots, pas un sigle.
    expect($generateur->pourProvisionDotation($provision)->libelle)->toBe("Rattachement — {$cas} : Ligne d’origine");
    expect($generateur->pourProvisionExtourne($provision)->libelle)->toBe("Extourne — {$cas} : Ligne d’origine");
})->with([
    'PCA' => [7, 'produit constaté d’avance'],
    'CCA' => [6, 'charge constatée d’avance'],
]);

test('le libellé est tronqué à la colonne : 255 caractères exactement, terminés par « … »', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $ligne->update(['libelle' => str_repeat('é', 255)]);

    $provision = provisionPourLigne($ligne->fresh());
    $generateur = app(EcritureGenerator::class);

    foreach ([$generateur->pourProvisionDotation($provision), $generateur->pourProvisionExtourne($provision)] as $ecriture) {
        // Ni plus (MySQL refuserait l'insertion), ni moins (on ne perd pas de texte
        // gratuitement) : la colonne est pleine, et la coupure se voit.
        expect(mb_strlen($ecriture->libelle))->toBe(255);
        expect($ecriture->libelle)->toEndWith('…');
        expect($ecriture->libelle)->toStartWith($ecriture->type_ecriture === 'extourne' ? 'Extourne — ' : 'Rattachement — ');

        expect($ecriture->lignes)->toHaveCount(2);
        foreach ($ecriture->lignes as $l) {
            expect($l->libelle)->toBe($ecriture->libelle);
        }
    }
});

test('un libellé qui tient dans la colonne n’est pas tronqué', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    // Le préfixe « Rattachement — produit constaté d’avance : » fait 43 caractères.
    $ligne->update(['libelle' => str_repeat('é', 212)]);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation(provisionPourLigne($ligne->fresh()));

    expect(mb_strlen($dotation->libelle))->toBe(255);
    expect($dotation->libelle)->not->toEndWith('…');
});

test('le libellé retombe sur l’intitulé du compte quand l’origine n’en a pas, nul ou vide', function (?string $libelleOrigine) {
    $ligne = $this->ligneOrigine(7, 175.0);
    $ligne->update(['libelle' => $libelleOrigine]);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation(provisionPourLigne($ligne->fresh()));

    // Le même repli que le récapitulatif : Provision::libelleEffectif(), jamais « … : ».
    expect($dotation->libelle)->toBe('Rattachement — produit constaté d’avance : '.$ligne->compte->intitule);
})->with([
    'libellé nul' => [null],
    'libellé vide' => [''],
]);

test('un montant nul ou négatif est refusé par le générateur lui-même', function (float $montant) {
    $ligne = $this->ligneOrigine(7, 175.0);

    // Non enregistré : le hook saving du modèle refuserait déjà ce montant. Le
    // générateur ne doit pas compter sur lui.
    $provision = (new Provision)->forceFill([
        'association_id' => TenantContext::currentId(),
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne->id,
        'montant' => $montant,
    ]);

    $generateur = app(EcritureGenerator::class);

    expect(fn () => $generateur->pourProvisionDotation($provision))->toThrow(RuntimeException::class, 'strictement positif');
    expect(fn () => $generateur->pourProvisionExtourne($provision))->toThrow(RuntimeException::class, 'strictement positif');
    expect(Transaction::where('journal', JournalComptable::Od->value)->count())->toBe(0);
})->with([
    'nul' => [0.0],
    'négatif' => [-175.0],
]);

test('un compte de résultat introuvable dans l’association courante est refusé (relations non chargées)', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    // On change d'association sans recharger le rattachement : ses relations ne
    // sont pas encore chargées, elles se résolvent sous le scope de l'autre et le
    // compte est introuvable.
    TenantContext::clear();
    TenantContext::boot(Association::factory()->create());

    expect(fn () => app(EcritureGenerator::class)->pourProvisionDotation($provision))
        ->toThrow(RuntimeException::class, 'introuvable dans cette association');

    expect(Transaction::query()->count())->toBe(0);
});

test('un compte de résultat qui appartient à une autre association est refusé (relations chargées)', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    // Relations chargées AVANT de changer d'association : le compte est bien là, c'est
    // celui de l'association d'origine. Seule la comparaison de son association à
    // l'association courante peut le refuser — c'est l'autre moitié de la garde.
    $provision->load('ligne.compte');
    expect($provision->compteResultat())->not->toBeNull();

    TenantContext::clear();
    TenantContext::boot(Association::factory()->create());

    expect(fn () => app(EcritureGenerator::class)->pourProvisionDotation($provision))
        ->toThrow(RuntimeException::class, 'introuvable dans cette association');
    expect(fn () => app(EcritureGenerator::class)->pourProvisionExtourne($provision))
        ->toThrow(RuntimeException::class, 'introuvable dans cette association');

    expect(Transaction::query()->count())->toBe(0);
});

// ── ProvisionPDService : la paire d'écritures ────────────────────────────────

test('aucune écriture de rattachement ne porte de débit ou de crédit négatif (AC-15)', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    app(ProvisionPDService::class)->generer($provision);

    $lignes = TransactionLigne::whereIn(
        'transaction_id',
        Transaction::where('provision_id', $provision->id)->pluck('id')
    )->get();

    expect($lignes)->toHaveCount(4);
    foreach ($lignes as $l) {
        expect((float) $l->debit)->toBeGreaterThanOrEqual(0.0);
        expect((float) $l->credit)->toBeGreaterThanOrEqual(0.0);
    }
});

test('generer produit une dotation et une extourne équilibrées', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    app(ProvisionPDService::class)->generer($provision);

    $ecritures = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();

    expect($ecritures->pluck('type_ecriture')->all())->toBe(['normale', 'extourne']);
    expect($ecritures->map(fn (Transaction $t) => $t->date->format('Y-m-d'))->all())->toBe(['2026-08-31', '2026-09-01']);
    expect($ecritures->pluck('journal')->unique()->all())->toBe([JournalComptable::Od]);
    expect($ecritures->every(fn (Transaction $t) => $t->equilibree === true))->toBeTrue();
});

test('generer régénère intégralement : un second appel remplace les deux écritures', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);
    $premieres = Transaction::where('provision_id', $provision->id)->pluck('id')->all();

    $service->generer($provision);
    $secondes = Transaction::where('provision_id', $provision->id)->pluck('id')->all();

    expect($secondes)->toHaveCount(2);
    expect(array_intersect($premieres, $secondes))->toBe([]);
    expect(TransactionLigne::whereIn('transaction_id', $secondes)->count())->toBe(4);
});

test('supprimer retire les deux écritures et leurs lignes', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);
    $ids = Transaction::where('provision_id', $provision->id)->pluck('id')->all();

    $service->supprimer($provision);

    expect(Transaction::withTrashed()->where('provision_id', $provision->id)->count())->toBe(0);
    expect(TransactionLigne::withTrashed()->whereIn('transaction_id', $ids)->count())->toBe(0);
});

// ── RattachementExerciceService : éligibilité et création ────────────────────
//
// motifDeRefus() est la source de vérité unique de l'éligibilité : le service s'y
// réfère, et l'écran et la requête de détection s'y référeront. Chaque garde a donc
// son test, et chaque refus est vérifié trois fois — le motif, le refus de
// rattacher(), et le fait que RIEN n'a été écrit (ni rattachement, ni écriture).

/**
 * Une ligne que ligneOrigine() ne fabrique pas : sur le compte et dans le sens
 * choisis, sur une pièce du 2026-04-08. Un compte nul donne une ligne sans compte
 * (héritage d'avant la partie double).
 */
function serviceRattachementLigne(?Compte $compte, float $debit, float $credit, TypeTransaction $type, JournalComptable $journal): TransactionLigne
{
    $transaction = Transaction::create([
        'association_id' => (int) TenantContext::currentId(),
        'type' => $type,
        'date' => '2026-04-08',
        'libelle' => 'Pièce d’origine',
        'montant_total' => max($debit, $credit),
        'saisi_par' => auth()->id(),
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => $journal,
    ]);

    return TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte?->id,
        'debit' => $debit,
        'credit' => $credit,
        'montant' => max($debit, $credit),
        'libelle' => 'Ligne d’origine',
    ]);
}

/**
 * Le refus complet d'une ligne : le motif la décrit, rattacher() lève une
 * RuntimeException portant ce motif, et la base n'a pas bougé d'une ligne.
 *
 * La ligne est relue : motifDeRefus() lit des relations, qu'une instance déjà
 * chargée ferait paraître à jour alors que la base a changé.
 */
function serviceRattachementAssertRefusee(TransactionLigne $ligne, string $fragmentDuMotif): void
{
    $service = app(RattachementExerciceService::class);
    $ligne = $ligne->fresh();

    expect($service->motifDeRefus($ligne))->toContain($fragmentDuMotif);

    $provisionsAvant = DB::table('provisions')->count();
    $transactionsAvant = DB::table('transactions')->count();

    expect(fn () => $service->rattacher($ligne, null, auth()->user()))
        ->toThrow(RuntimeException::class, $fragmentDuMotif);

    expect(DB::table('provisions')->count())->toBe($provisionsAvant);
    expect(DB::table('transactions')->count())->toBe($transactionsAvant);
}

/**
 * Une pièce réellement annulée par TransactionExtourneService, l'annulation étant datée
 * de $dateAnnulation (la date comptable de la pièce miroir ; extournee_at, lui, vaut
 * now(), soit le 2026-01-15 figé). La pièce d'origine est équilibrée : une ligne de
 * produit au crédit et un 411 au débit (classe 7), ou une ligne de charge au débit et
 * un 401 au crédit (classe 6).
 *
 * @return array{0: TransactionLigne, 1: TransactionLigne, 2: Extourne} la ligne 6/7 de la
 *                                                                      pièce annulée, celle de l'annulation, et l'enregistrement
 */
function serviceRattachementPieceAnnulee(int $classe, string $dateAnnulation): array
{
    $assocId = (int) TenantContext::currentId();
    $estProduit = $classe === 7;
    $montant = $estProduit ? 175.0 : 90.0;
    $compteResultat = ($estProduit ? Compte::factory()->recette() : Compte::factory()->depense())
        ->create(['association_id' => $assocId, 'actif' => true]);
    $compteTiers = Compte::where('numero_pcg', $estProduit ? '411' : '401')->firstOrFail();

    $origine = Transaction::create([
        'association_id' => $assocId,
        'type' => $estProduit ? TypeTransaction::Recette : TypeTransaction::Depense,
        'date' => '2026-04-08',
        'libelle' => 'Pièce à annuler',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Virement,
        'saisi_par' => auth()->id(),
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => $estProduit ? JournalComptable::Vente : JournalComptable::Achat,
    ]);
    $ligneResultat = TransactionLigne::create([
        'transaction_id' => $origine->id,
        'compte_id' => $compteResultat->id,
        'debit' => $estProduit ? 0 : $montant,
        'credit' => $estProduit ? $montant : 0,
        'montant' => $montant,
        'libelle' => 'Ligne d’origine',
    ]);
    TransactionLigne::create([
        'transaction_id' => $origine->id,
        'compte_id' => $compteTiers->id,
        'debit' => $estProduit ? $montant : 0,
        'credit' => $estProduit ? 0 : $montant,
        'montant' => $montant,
        'libelle' => 'Contrepartie',
    ]);

    $extourne = app(TransactionExtourneService::class)->extourner(
        $origine,
        ExtournePayload::fromOrigine($origine, ['date' => $dateAnnulation]),
    );

    $ligneMiroir = TransactionLigne::where('transaction_id', $extourne->transaction_extourne_id)
        ->where('compte_id', $compteResultat->id)
        ->sole();

    return [$ligneResultat, $ligneMiroir, $extourne];
}

/**
 * Exécute $changement au tout début de la transaction du service, une seule fois, puis
 * rattache la ligne. Rend ce que rattacher() a rendu, ou lance ce qu'il a lancé.
 */
function serviceRattachementAvecChangementConcurrent(TransactionLigne $ligne, Closure $changement, User $auteur): Provision
{
    $niveauAvant = DB::transactionLevel();
    $fait = false;

    DB::listen(function ($requete) use (&$fait, $niveauAvant, $changement): void {
        if ($fait || DB::transactionLevel() <= $niveauAvant || ! str_contains($requete->sql, 'association')) {
            return;
        }

        $fait = true;
        $changement();
    });

    return app(RattachementExerciceService::class)->rattacher($ligne, null, $auteur);
}

describe('RattachementExerciceService : éligibilité (motifDeRefus)', function () {
    test('une ligne de produit au crédit ou de charge au débit, ventilée sur une opération future, est rattachable', function (int $classe, float $montant) {
        $operation = Operation::factory()->create([
            'association_id' => TenantContext::currentId(),
            'date_debut' => '2026-09-18',
            'date_fin' => '2027-06-18',
        ]);
        $ligne = $this->ligneOrigine($classe, $montant, operationId: (int) $operation->id);

        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne))->toBeNull();
    })->with('rattachement_pca_cca');

    test('motifDeRefus() est une lecture simple : aucune de ses requêtes ne verrouille', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $requetes = [];

        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne))->toBeNull();

        // Garde anti-test-vide : le motif a bien lu la table des rattachements.
        expect(array_filter($requetes, fn (string $sql) => str_contains($sql, 'provisions')))->not->toBeEmpty();

        // C'est rattacher() qui verrouille, dans sa transaction ; l'écran et la détection
        // appellent motifDeRefus() hors de toute écriture. (Sous SQLite « for update » n'existe pas.)
        foreach ($requetes as $sql) {
            expect($sql)->not->toContain('for update');
        }
    });

    test('une ligne dont la pièce a été supprimée est refusée : la pièce n’existe plus', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $ligne->transaction->delete();

        serviceRattachementAssertRefusee($ligne, 'La pièce de cette ligne a été supprimée.');
    });

    test('AC-11 — après un rattachement, la ligne 6/7 de la dotation et celle de l’extourne sont refusées comme écritures de rattachement', function (int $classe, float $montant) {
        $origine = $this->ligneOrigine($classe, $montant);
        $provision = app(RattachementExerciceService::class)->rattacher($origine, null, $this->user);

        $ecritures = Transaction::where('provision_id', $provision->id)->get()->keyBy('type_ecriture');
        expect($ecritures->keys()->sort()->values()->all())->toBe(['extourne', 'normale']);

        // La dotation d'une PCA est au débit d'un compte de classe 7, celle d'une CCA
        // au crédit d'un compte de classe 6 : sens inverse. Leur extourne, elle, est
        // dans le sens attendu. Seul le motif « écriture de rattachement » les distingue
        // de ce qu'une autre garde (sens, journal OD) dirait : on exige celui-là.
        foreach ($ecritures as $ecriture) {
            $ligne = TransactionLigne::where('transaction_id', $ecriture->id)
                ->where('compte_id', $origine->compte_id)
                ->sole();

            serviceRattachementAssertRefusee($ligne, 'écriture de rattachement');
        }
    })->with('rattachement_pca_cca');

    test('D14 — une ligne du journal des opérations diverses, qui n’est pas une écriture de rattachement, est refusée', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        Transaction::whereKey($ligne->transaction_id)->update(['journal' => JournalComptable::Od->value]);

        // La prémisse : ni sens inverse, ni écriture de rattachement. Seul le journal refuse.
        expect(Transaction::find($ligne->transaction_id)->provision_id)->toBeNull();

        serviceRattachementAssertRefusee($ligne, 'inventaire');
    })->with('rattachement_pca_cca');

    // D13 — la pièce annulée se juge sur la DATE COMPTABLE de l'annulation (celle de la
    // pièce miroir), pas sur l'horodatage de l'action (extournee_at, = now()). Annulée
    // dans N, rattacher l'origine retirerait deux fois le même montant ; annulée dans N+1
    // (un remboursement après le 31/08), rattacher est juste et c'est le refus qui
    // laisserait N faux. Une pièce qui EST une annulation ne se rattache jamais.
    // Trois déclencheurs distincts, chacun seul vrai dans son test.

    test('D13 — une pièce dont type_ecriture vaut extourne (une annulation) est refusée', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        Transaction::whereKey($ligne->transaction_id)->update(['type_ecriture' => 'extourne']);

        // Les autres déclencheurs sont faux.
        $piece = Transaction::find($ligne->transaction_id);
        expect($piece->extournee_at)->toBeNull();
        expect(Extourne::where('transaction_extourne_id', $piece->id)->exists())->toBeFalse();

        serviceRattachementAssertRefusee($ligne, 'annule une autre pièce');
    })->with('rattachement_pca_cca');

    test('D13 — une pièce référencée comme annulation dans la table des extournes est refusée', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        $pieceAnnulee = $this->ligneOrigine($classe, $montant);

        Extourne::create([
            'transaction_origine_id' => $pieceAnnulee->transaction_id,
            'transaction_extourne_id' => $ligne->transaction_id,
            'created_by' => $this->user->id,
        ]);

        $piece = Transaction::find($ligne->transaction_id);
        expect($piece->extournee_at)->toBeNull();
        expect($piece->type_ecriture)->toBe('normale');

        serviceRattachementAssertRefusee($ligne, 'annule une autre pièce');
    })->with('rattachement_pca_cca');

    test('D13 — la pièce annulée dans l’exercice N, jusqu’à son dernier jour inclus, est refusée', function (int $classe, string $dateAnnulation) {
        [$ligneOrigine] = serviceRattachementPieceAnnulee($classe, $dateAnnulation);

        serviceRattachementAssertRefusee($ligneOrigine, 'annulée dans le même exercice');
    })->with([
        'PCA annulée en cours d’exercice' => [7, '2026-04-20'],
        'PCA annulée le dernier jour de N' => [7, '2026-08-31'],
        'CCA annulée le dernier jour de N' => [6, '2026-08-31'],
    ]);

    test('D13 — la pièce annulée dans l’exercice N+1 reste rattachable : motif nul, et rattacher() réussit', function (int $classe, string $dateAnnulation) {
        [$ligneOrigine] = serviceRattachementPieceAnnulee($classe, $dateAnnulation);
        $service = app(RattachementExerciceService::class);

        // La prémisse : la pièce est bien annulée, extournee_at est posé (à now(), dans N).
        $piece = Transaction::find($ligneOrigine->transaction_id);
        expect($piece->extournee_at)->not->toBeNull();
        expect($piece->extournee_at->toDateString())->toBe('2026-01-15');

        expect($service->motifDeRefus($ligneOrigine->fresh()))->toBeNull();

        $provision = $service->rattacher($ligneOrigine->fresh(), null, $this->user);

        expect((int) $provision->exercice)->toBe(2025);
        expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
    })->with([
        'PCA annulée le premier jour de N+1' => [7, '2026-09-01'],
        'CCA annulée le premier jour de N+1' => [6, '2026-09-01'],
        'PCA annulée plus tard dans N+1' => [7, '2027-02-01'],
    ]);

    test('D13 — la ligne de l’annulation elle-même est refusée, quelle que soit la date de l’annulation', function (int $classe, string $dateAnnulation) {
        [, $ligneMiroir] = serviceRattachementPieceAnnulee($classe, $dateAnnulation);

        // La prémisse : le miroir est bien une annulation, sur le même compte de produit ou de charge.
        expect($ligneMiroir->transaction->type_ecriture)->toBe('extourne');

        serviceRattachementAssertRefusee($ligneMiroir, 'annule une autre pièce');
    })->with([
        'PCA annulée dans N' => [7, '2026-04-20'],
        'PCA annulée dans N+1' => [7, '2026-09-01'],
        'CCA annulée dans N+1' => [6, '2026-09-01'],
    ]);

    test('D13 — une pièce annulée dont la date d’annulation est introuvable est refusée par prudence', function (string $cas) {
        if ($cas === 'sans enregistrement') {
            // extournee_at est posé, mais aucun enregistrement d'annulation ne permet de dater.
            $ligne = $this->ligneOrigine(7, 175.0);
            Transaction::whereKey($ligne->transaction_id)->update(['extournee_at' => now()]);
            expect(Extourne::count())->toBe(0);
        } else {
            // L'enregistrement existe, mais la pièce miroir a été supprimée.
            [$ligne, $ligneMiroir] = serviceRattachementPieceAnnulee(7, '2026-09-01');
            $ligneMiroir->transaction->delete();
            expect(Extourne::count())->toBe(1);
        }

        serviceRattachementAssertRefusee($ligne, 'introuvable');
    })->with([
        'sans enregistrement d’annulation' => ['sans enregistrement'],
        'pièce d’annulation supprimée' => ['miroir supprimé'],
    ]);

    test('une ligne sur un compte de classe 4, ou sans compte, est refusée : seule une charge ou un produit se rattache', function (?string $numero, float $debit, float $credit) {
        $compte = $numero === null ? null : Compte::where('numero_pcg', $numero)->firstOrFail();
        $ligne = serviceRattachementLigne($compte, $debit, $credit, TypeTransaction::Recette, JournalComptable::Vente);

        serviceRattachementAssertRefusee($ligne, 'charge ou de produit');
    })->with([
        '411 au débit' => ['411', 175.0, 0.0],
        '401 au crédit' => ['401', 0.0, 175.0],
        'sans compte' => [null, 0.0, 175.0],
    ]);

    test('la contrepartie (411, 401) d’une écriture d’inventaire ou d’une pièce annulée reçoit le motif de classe, qui la décrit', function (string $cas) {
        if ($cas === 'journal OD') {
            $compte = Compte::where('numero_pcg', '411')->firstOrFail();
            $ligne = serviceRattachementLigne($compte, 175.0, 0.0, TypeTransaction::Recette, JournalComptable::Od);
        } else {
            [$ligneProduit] = serviceRattachementPieceAnnulee(7, '2026-04-20');
            $ligne = TransactionLigne::where('transaction_id', $ligneProduit->transaction_id)
                ->where('id', '!=', $ligneProduit->id)
                ->sole();
        }

        serviceRattachementAssertRefusee($ligne, 'charge ou de produit');
    })->with([
        'écriture d’inventaire' => ['journal OD'],
        'pièce annulée' => ['pièce annulée'],
    ]);

    test('AC-5 — une ligne de sens inverse (709A au débit, 609 au crédit) est refusée, et rien n’est créé', function (string $numero, float $debit, float $credit, string $attendu) {
        $compte = Compte::factory()->numero($numero)->create(['association_id' => TenantContext::currentId(), 'actif' => true]);
        $estProduit = (int) $compte->classe === 7;
        $ligne = serviceRattachementLigne(
            $compte,
            $debit,
            $credit,
            $estProduit ? TypeTransaction::Recette : TypeTransaction::Depense,
            $estProduit ? JournalComptable::Vente : JournalComptable::Achat,
        );

        serviceRattachementAssertRefusee($ligne, $attendu);

        // Le motif dit ce que ferait le rattachement : il ne reporterait pas l'effet, il l'amplifierait.
        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne->fresh()))
            ->toContain('au lieu de le reporter sur l’exercice suivant');
    })->with([
        '709A (produit) au débit' => ['709A', 50.0, 0.0, 'sens inverse (709A au débit)'],
        '609 (charge) au crédit' => ['609', 0.0, 50.0, 'sens inverse (609 au crédit)'],
    ]);

    test('AC-6 — une ligne porteuse d’affectations est refusée avec le motif partagé, et n’est jamais traitée au grain ligne', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);

        foreach ([100.0, $montant - 100.0] as $part) {
            $operation = Operation::factory()->create([
                'association_id' => TenantContext::currentId(),
                'date_debut' => '2026-09-18',
                'date_fin' => '2027-06-18',
            ]);
            TransactionLigneAffectation::create([
                'transaction_ligne_id' => $ligne->id,
                'operation_id' => $operation->id,
                'montant' => $part,
            ]);
        }

        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne->fresh()))
            ->toBe(RattachementExerciceService::MOTIF_AFFECTATIONS);

        // Le vocabulaire de l'écran (« ventiler »), pas le nom de la table.
        expect(RattachementExerciceService::MOTIF_AFFECTATIONS)
            ->toContain('ventilée entre plusieurs opérations')
            ->not->toContain('affectations');

        serviceRattachementAssertRefusee($ligne, RattachementExerciceService::MOTIF_AFFECTATIONS);
    })->with([
        'PCA' => [7, 175.0],
        'CCA' => [6, 190.0],
    ]);

    test('une ligne déjà rattachée est refusée, et un second appel ne crée pas de second rattachement', function (int $classe, float $montant) {
        $ligne = $this->ligneOrigine($classe, $montant);
        app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        expect(DB::table('provisions')->count())->toBe(1);

        serviceRattachementAssertRefusee($ligne, 'déjà rattachée');

        expect(DB::table('provisions')->count())->toBe(1);
        expect(Transaction::whereNotNull('provision_id')->count())->toBe(2);
    })->with('rattachement_pca_cca');
});

describe('RattachementExerciceService : création', function () {
    test('rattacher() crée le rattachement et ses deux écritures : exercice, montant, origine, notes et auteur', function (int $classe, float $montant, CasProvision $cas) {
        $ligne = $this->ligneOrigine($classe, $montant);

        $provision = app(RattachementExerciceService::class)->rattacher($ligne, 'Prestation de septembre', $this->user);

        expect($provision)->toBeInstanceOf(Provision::class);
        expect($provision->exists)->toBeTrue();
        expect((int) $provision->exercice)->toBe(2025);
        expect((float) $provision->montant)->toBe($montant);
        expect((int) $provision->transaction_ligne_id)->toBe((int) $ligne->id);
        expect($provision->notes)->toBe('Prestation de septembre');
        expect((int) $provision->saisi_par)->toBe((int) $this->user->id);
        expect($provision->cas())->toBe($cas);

        $ecritures = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();
        expect($ecritures)->toHaveCount(2);
        expect($ecritures->map(fn (Transaction $t) => $t->date->format('Y-m-d'))->all())->toBe(['2026-08-31', '2026-09-01']);
        expect($ecritures->pluck('type_ecriture')->all())->toBe(['normale', 'extourne']);

        // Le sens, en euros : la dotation corrige N, l'extourne le rend à N+1.
        $impactDotation = $classe === 7 ? -$montant : $montant;
        expect(impactSurLeResultat($ecritures[0]->load('lignes.compte')))->toBe($impactDotation);
        expect(impactSurLeResultat($ecritures[1]->load('lignes.compte')))->toBe(-$impactDotation);

        expect($ligne->fresh()->estRattachee())->toBeTrue();
    })->with([
        'PCA' => [7, 175.0, CasProvision::ProduitConstateDavance],
        'CCA' => [6, 90.0, CasProvision::ChargeConstateeDavance],
    ]);

    test('le libellé du rattachement reste nul : il est celui de la ligne d’origine, lu par libelleEffectif()', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        $provision = app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        expect($provision->libelle)->toBeNull();
        expect($provision->libelleEffectif())->toBe('Ligne d’origine');
    });

    test('rattacherParId() résout la ligne dans l’association courante et rattache', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        $provision = app(RattachementExerciceService::class)->rattacherParId((int) $ligne->id, null, $this->user);

        expect((int) $provision->transaction_ligne_id)->toBe((int) $ligne->id);
        expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
    });

    test('la création est journalisée : rattachement, ligne, cas, exercice et montant', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $spy = Log::spy();

        $provision = app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        $attendu = [
            'provision_id' => (int) $provision->id,
            'transaction_ligne_id' => (int) $ligne->id,
            'cas' => 'pca',
            'exercice' => 2025,
            'montant' => 175.0,
        ];
        ksort($attendu);

        $spy->shouldHaveReceived('info')
            ->with('[Rattachement] écriture de rattachement créée', Mockery::on(function ($contexte) use ($attendu) {
                ksort($contexte);

                return $contexte === $attendu;
            }))
            ->once();
    });

    test('sans aucune ligne exercices pour N+1, le rattachement réussit et l’extourne est générée', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        expect(Exercice::where('annee', 2026)->exists())->toBeFalse();

        $ligne = $this->ligneOrigine(7, 175.0);
        $provision = app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        $extourne = Transaction::where('provision_id', $provision->id)->where('type_ecriture', 'extourne')->sole();
        expect($extourne->date->format('Y-m-d'))->toBe('2026-09-01');
        expect(Exercice::where('annee', 2026)->exists())->toBeFalse();
    });

    test('AC-9 — la création est refusée si N est clôturé, ou si N+1 est clôturé, et rien n’est créé', function (int $anneeCloturee, string $libelle) {
        Exercice::create(['annee' => $anneeCloturee, 'statut' => StatutExercice::Cloture]);
        $ligne = $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        expect(fn () => app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user))
            ->toThrow(ExerciceCloturedException::class, $libelle);

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
        expect($ligne->fresh()->estRattachee())->toBeFalse();
    })->with([
        'N (2025-2026) clôturé' => [2025, '2025-2026'],
        'N+1 (2026-2027) clôturé' => [2026, '2026-2027'],
    ]);

    test('l’exercice du rattachement est celui de la date de la pièce, pas celui d’aujourd’hui', function (string $date, int $exercice) {
        // « Aujourd'hui » est figé au 2026-01-15 (exercice 2025) : une pièce d'un autre
        // exercice ne peut être bien classée que par sa propre date.
        $ligne = $this->ligneOrigine(7, 175.0);
        Transaction::whereKey($ligne->transaction_id)->update(['date' => $date]);

        $provision = app(RattachementExerciceService::class)->rattacher($ligne->fresh(), null, $this->user);

        expect((int) $provision->exercice)->toBe($exercice);

        $ecritures = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();
        expect($ecritures->map(fn (Transaction $t) => $t->date->format('Y-m-d'))->all())
            ->toBe([($exercice + 1).'-08-31', ($exercice + 1).'-09-01']);
    })->with([
        'avril 2025 : exercice 2024' => ['2025-04-08', 2024],
        'veille de la bascule : exercice 2024' => ['2025-08-31', 2024],
        'premier jour de l’exercice 2025' => ['2025-09-01', 2025],
        'dernier jour de l’exercice 2025' => ['2026-08-31', 2025],
    ]);

    test('les verrous sont pris dans l’ordre canonique — association, exercice N, exercice N+1, pièce, ligne — puis « déjà rattachée » est relu sous verrou, avant toute écriture', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $niveauAvant = DB::transactionLevel();
        $requetes = [];

        DB::listen(function ($requete) use (&$requetes, $niveauAvant): void {
            if (DB::transactionLevel() > $niveauAvant) {
                $requetes[] = $requete;
            }
        });

        app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user);

        // Seules comptent les requêtes qui précèdent la première écriture : les verrous
        // sont posés avant que l'écriture de rattachement ne commence.
        $premiereEcriture = collect($requetes)->search(fn ($requete) => str_starts_with(mb_strtolower($requete->sql), 'insert'));
        expect($premiereEcriture)->toBeInt();
        $avantEcriture = array_slice($requetes, 0, $premiereEcriture);

        $table = fn ($requete): string => preg_match('/from\s+[`"]?(\w+)[`"]?/i', $requete->sql, $m) === 1 ? $m[1] : '?';
        $indice = function (string $table_, ?int $annee = null) use ($avantEcriture, $table): int {
            foreach ($avantEcriture as $i => $requete) {
                if ($table($requete) === $table_ && ($annee === null || in_array($annee, $requete->bindings, true))) {
                    return $i;
                }
            }

            throw new RuntimeException("Aucun verrou sur {$table_}".($annee !== null ? " ({$annee})" : '').' avant la première écriture.');
        };

        $indices = [
            'association' => $indice('association'),
            'exercice N' => $indice('exercices', 2025),
            'exercice N+1' => $indice('exercices', 2026),
            'pièce' => $indice('transactions'),
            'ligne' => $indice('transaction_lignes'),
            // « Déjà rattachée », relu sous les verrous : après la ligne, avant l'écriture.
            'recontrôle' => $indice('provisions'),
        ];

        // Strictement croissants : chaque verrou est pris après le précédent.
        expect(array_values($indices))->toBe(collect($indices)->sort()->values()->all());
        expect(count(array_unique($indices)))->toBe(6);

        // SQLite n'écrit pas « for update » : seul MySQL/MariaDB prouve que ces lectures verrouillent.
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($indices as $i) {
                expect($avantEcriture[$i]->sql)->toContain('for update');
            }
        }
    });

    test('AC-12 — l’identifiant d’une ligne d’une autre association est traité comme inexistant', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $associationDeLaLigne = TenantContext::current();

        TenantContext::clear();
        TenantContext::boot(Association::factory()->create());

        $transactionsAvant = DB::table('transactions')->count();

        expect(fn () => app(RattachementExerciceService::class)->rattacherParId((int) $ligne->id, null, $this->user))
            ->toThrow(RuntimeException::class, 'n’existe pas');

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);

        TenantContext::clear();
        TenantContext::boot($associationDeLaLigne);

        expect($ligne->fresh()->estRattachee())->toBeFalse();
    });

    test('un identifiant de ligne inexistant est refusé comme une ligne d’une autre association', function () {
        $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        expect(fn () => app(RattachementExerciceService::class)->rattacherParId(999999, null, $this->user))
            ->toThrow(RuntimeException::class, 'n’existe pas');

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
    });

    // I1 — la ligne et sa pièce sont verrouillées dans la transaction, puis JUGÉES dans cet
    // état verrouillé : ni le montant ni l'éligibilité ne viennent de l'état lu avant. Un
    // écrivain qui ne prend pas le verrou d'association (synchronisation HelloAsso, édition,
    // annulation) peut avoir changé la ligne entre motifDeRefus() et le verrou. Chaque test
    // fait ce changement DANS la transaction du service, sur sa toute première requête (le
    // verrou d'association) : c'est exactement la fenêtre que le verrou de pièce et de ligne
    // doit fermer.

    test('I1 — le montant du rattachement est celui de la ligne verrouillée, pas celui lu avant', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        // La synchronisation HelloAsso (remboursement partiel) ramène la ligne à 100 €.
        $provision = serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->update(['credit' => 100, 'montant' => 100]),
            $this->user,
        );

        expect((float) $provision->montant)->toBe(100.0);

        $ecritures = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();
        expect($ecritures)->toHaveCount(2);
        expect(impactSurLeResultat($ecritures[0]->load('lignes.compte')))->toBe(-100.0);
        expect(impactSurLeResultat($ecritures[1]->load('lignes.compte')))->toBe(100.0);
    });

    test('I1 — une ligne recréée ou supprimée entre-temps est refusée lisiblement, pas par une erreur technique', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        // TransactionService::update() détruit et recrée toutes les lignes de la pièce.
        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->delete(),
            $this->user,
        ))->toThrow(RuntimeException::class, 'Cette ligne n’existe plus : sa pièce a été modifiée entre-temps. Rechargez la page.');

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
    });

    test('I1 — une pièce supprimée entre-temps est refusée lisiblement', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transactions')->where('id', $ligne->transaction_id)->update(['deleted_at' => now()]),
            $this->user,
        ))->toThrow(RuntimeException::class, 'Cette ligne n’existe plus');

        expect(DB::table('provisions')->count())->toBe(0);
    });

    test('I1 — une ligne passée à une autre pièce entre-temps est refusée lisiblement', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $autrePiece = $this->ligneOrigine(7, 50.0);

        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->update(['transaction_id' => $autrePiece->transaction_id]),
            $this->user,
        ))->toThrow(RuntimeException::class, 'Cette ligne n’existe plus');

        expect(DB::table('provisions')->count())->toBe(0);
    });

    test('I1 — l’éligibilité est rejugée sur la pièce verrouillée : annulée, ou passée en journal d’opérations diverses, entre-temps', function (array $changement, string $fragment) {
        $ligne = $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        // Prémisse : avant le changement, la ligne est rattachable.
        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne))->toBeNull();

        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transactions')->where('id', $ligne->transaction_id)->update($changement),
            $this->user,
        ))->toThrow(RuntimeException::class, $fragment);

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
    })->with([
        'annulée (extournee_at posé)' => [['extournee_at' => '2026-02-01 10:00:00'], 'annulée'],
        'passée en journal d’opérations diverses' => [['journal' => 'od'], 'inventaire'],
    ]);

    test('I1 — l’éligibilité est rejugée sur la ligne verrouillée : reclassée sur un compte de classe 4, ou passée de sens, entre-temps', function (string $changement, string $fragment) {
        $ligne = $this->ligneOrigine(7, 175.0);
        $transactionsAvant = DB::table('transactions')->count();

        // Prémisse : avant le changement, la ligne est rattachable (et ses relations sont chargées).
        expect(app(RattachementExerciceService::class)->motifDeRefus($ligne))->toBeNull();

        $modification = $changement === 'compte'
            ? ['compte_id' => Compte::where('numero_pcg', '411')->firstOrFail()->id]
            : ['credit' => 0, 'debit' => 175];

        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transaction_lignes')->where('id', $ligne->id)->update($modification),
            $this->user,
        ))->toThrow(RuntimeException::class, $fragment);

        expect(DB::table('provisions')->count())->toBe(0);
        expect(DB::table('transactions')->count())->toBe($transactionsAvant);
    })->with([
        'reclassée sur un 411' => ['compte', 'charge ou de produit'],
        'passée au débit (sens inverse)' => ['sens', 'sens inverse'],
    ]);

    test('I1 — une pièce redatée dans un autre exercice entre-temps est refusée lisiblement', function () {
        $ligne = $this->ligneOrigine(7, 175.0);

        // Les exercices verrouillés sont ceux de l'ancienne date : le rattachement ne peut
        // pas se poursuivre sous des verrous qui ne sont plus les bons.
        expect(fn () => serviceRattachementAvecChangementConcurrent(
            $ligne,
            fn () => DB::table('transactions')->where('id', $ligne->transaction_id)->update(['date' => '2025-04-08']),
            $this->user,
        ))->toThrow(RuntimeException::class, 'La date de la pièce a changé');

        expect(DB::table('provisions')->count())->toBe(0);
    });

    test('les notes vides ou blanches deviennent nulles, les autres sont rognées', function (?string $notes, ?string $attendu) {
        $ligne = $this->ligneOrigine(7, 175.0);

        $provision = app(RattachementExerciceService::class)->rattacher($ligne, $notes, $this->user);

        expect($provision->fresh()->notes)->toBe($attendu);
    })->with([
        'nulles' => [null, null],
        'vides' => ['', null],
        'blanches' => ["  \n ", null],
        'rognées' => ['  Prestation de septembre ', 'Prestation de septembre'],
    ]);

    test('le recontrôle « déjà rattachée » a lieu dans la transaction, après le verrou de la ligne : jamais deux rattachements', function () {
        $ligne = $this->ligneOrigine(7, 175.0);
        $niveauAvant = DB::transactionLevel();
        $injecte = false;

        // Fait apparaître un rattachement de la même ligne DANS la transaction du
        // service, juste après sa première lecture de transaction_lignes — le verrou de
        // la ligne (le test d'ordre des verrous établit que c'est la première). Le
        // contrôle de motifDeRefus(), fait avant la transaction, a déjà conclu que la ligne
        // était libre : seul un recontrôle placé après ce verrou peut voir le rattachement.
        // Un recontrôle déplacé avant la transaction, ou supprimé, fait tomber ce test : le
        // service créerait un second rattachement au lieu de lever.
        //
        // Ce que le test ne prouve pas : l'exclusion mutuelle de deux requêtes. C'est le
        // moteur qui la fournit, par les verrous (association, puis ligne) ; une vraie
        // concurrence ne se provoque pas dans un seul processus. Il prouve où est le
        // recontrôle et ce qu'il fait de ce qu'il trouve.
        DB::listen(function ($requete) use (&$injecte, $ligne, $niveauAvant): void {
            if ($injecte
                || DB::transactionLevel() <= $niveauAvant
                || ! str_contains($requete->sql, 'transaction_lignes')) {
                return;
            }

            $injecte = true;
            provisionPourLigne($ligne);
        });

        expect(fn () => app(RattachementExerciceService::class)->rattacher($ligne, null, $this->user))
            ->toThrow(RuntimeException::class, 'déjà rattachée');

        expect($injecte)->toBeTrue();
        // Le rattachement injecté vivait dans la transaction du service : son refus
        // l'annule avec elle. Il ne reste ni rattachement ni écriture.
        expect(DB::table('provisions')->count())->toBe(0);
        expect(Transaction::whereNotNull('provision_id')->count())->toBe(0);
    });
});
