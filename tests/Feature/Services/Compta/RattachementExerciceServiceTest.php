<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Livewire\OperationDetail;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\ProvisionPDService;
use App\Services\TransactionUniverselleService;
use App\Tenant\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

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

test('PCA : dotation 7xx D / 487 C au dernier jour de N, opération portée par la seule ligne 7xx', function () {
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
// L'erreur de la spec de juin 2026 a survécu dix-huit mois parce qu'aucun test
// n'affirmait CE QUE L'ÉCRITURE FAIT AU RÉSULTAT, seulement quels comptes elle
// touchait. Ces tests le disent en euros, par cas, dotation puis extourne.

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
})->with([
    'PCA' => [7, '487'],
    'CCA' => [6, '486'],
]);

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
})->with([
    'PCA' => [7, '487'],
    'CCA' => [6, '486'],
]);

test('une origine sans opération ni séance donne des lignes sans opération ni séance', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $provision = provisionPourLigne($ligne);

    $dotation = app(EcritureGenerator::class)->pourProvisionDotation($provision);

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

        foreach ($ecriture->lignes as $l) {
            expect($l->tiers_id)->toBeNull();
        }
    }
})->with([
    'PCA' => [7, 175.0],
    'CCA' => [6, 90.0],
]);

// ── L'écran de l'opération ───────────────────────────────────────────────────
//
// Une écriture de rattachement n'est ni une recette ni une dépense de l'opération :
// c'est une écriture d'inventaire. Elle corrige son compte de résultat (le CR par
// opération la lit), mais OperationDetail ne doit afficher aucun de ses trois
// totaux modifié. Sans le filtre de journal, une ligne de classe 7 dans une
// transaction typée « dépense » donnait « recettes 350 / dépenses 175 » pour une
// opération qui avait encaissé 175.

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
// est donc négatif, comme celui d'une extourne. C'est la commande réelle qui doit se
// taire — recalculer la règle ici à la main ne prouverait rien.

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
})->with([
    'PCA' => [7, 175.0],
    'CCA' => [6, 90.0],
]);

// ── Le type de transaction : une écriture d'inventaire n'est ni recette ni dépense ──
//
// Toutes les écritures de rattachement sont typées « dépense », comme les dotations
// d'amortissement : le type ne sert qu'à les tenir hors des écrans de travail. Typées
// « recette », une sur deux apparaissait dans « Toutes les transactions » avec un
// badge et une couleur qui disaient l'inverse de ce qu'elle fait (« recette » en
// rouge sur −90 pour la dotation d'une CCA).

test('les deux écritures d’un rattachement sont typées dépense, pour une PCA comme pour une CCA', function (int $classe, float $montant) {
    $ligne = $this->ligneOrigine($classe, $montant);
    $provision = provisionPourLigne($ligne);

    $generateur = app(EcritureGenerator::class);

    expect($generateur->pourProvisionDotation($provision)->type)->toBe(TypeTransaction::Depense);
    expect($generateur->pourProvisionExtourne($provision)->type)->toBe(TypeTransaction::Depense);
})->with([
    'PCA' => [7, 175.0],
    'CCA' => [6, 90.0],
]);

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
})->with([
    'PCA' => [7, 175.0],
    'CCA' => [6, 90.0],
]);

// ── Gardes ───────────────────────────────────────────────────────────────────

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

test('le libellé tient dans les 255 caractères de la colonne, même quand celui de l’origine est long', function () {
    $ligne = $this->ligneOrigine(7, 175.0);
    $ligne->update(['libelle' => str_repeat('é', 255)]);

    $provision = provisionPourLigne($ligne->fresh());
    $generateur = app(EcritureGenerator::class);

    foreach ([$generateur->pourProvisionDotation($provision), $generateur->pourProvisionExtourne($provision)] as $ecriture) {
        expect(mb_strlen($ecriture->libelle))->toBeLessThanOrEqual(255);
        expect($ecriture->libelle)->toStartWith($ecriture->type_ecriture === 'extourne' ? 'Extourne — ' : 'Rattachement — ');

        foreach ($ecriture->lignes as $l) {
            expect(mb_strlen($l->libelle))->toBeLessThanOrEqual(255);
        }
    }
});

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
