<?php

declare(strict_types=1);

// Le budget est la TROISIÈME source de lignes du compte de résultat, à côté
// des écritures de N et de celles de N-1. Un compte budgété qui n'a bougé ni
// en N ni en N-1 — typiquement un compte neuf ouvert pour une activité qui
// n'a pas encore démarré — n'avait aucune ligne, et son enveloppe manquait au
// total budget de la section.

use App\Livewire\RapportCompteResultat;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

/**
 * Ligne d'un compte dans une section du rapport, ou null s'il n'y figure pas.
 *
 * @param  list<array>  $section
 */
function ligneCompteDuRapport(array $section, int $compteId): ?array
{
    foreach ($section as $famille) {
        foreach ($famille['comptes'] as $compte) {
            if ((int) $compte['compte_id'] === $compteId) {
                return $compte;
            }
        }
    }

    return null;
}

it('une enveloppe de classe 6 sans aucun mouvement cree sa ligne du seul cote des charges', function (): void {
    $compte = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location salle',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 1500.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);

    $ligne = ligneCompteDuRapport($rapport['charges'], (int) $compte->id);

    expect($ligne)->not->toBeNull()
        ->and($ligne['compte_nom'])->toBe('Location salle')
        ->and((float) $ligne['montant_n'])->toBe(0.0)
        ->and($ligne['montant_n1'])->toBeNull()
        ->and((float) $ligne['budget'])->toBe(1500.0);

    // Le piège : la même carte de budget est passée aux DEUX sections. Sans
    // filtre par classe, ce compte de charge apparaîtrait aussi en produit et
    // fausserait les deux totaux.
    expect(ligneCompteDuRapport($rapport['produits'], (int) $compte->id))->toBeNull();
});

it('une enveloppe de classe 7 sans aucun mouvement cree sa ligne du seul cote des produits', function (): void {
    $compte = Compte::factory()->numero('756')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Mécénat',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 800.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);

    $ligne = ligneCompteDuRapport($rapport['produits'], (int) $compte->id);

    expect($ligne)->not->toBeNull()
        ->and($ligne['compte_nom'])->toBe('Mécénat')
        ->and((float) $ligne['montant_n'])->toBe(0.0)
        ->and($ligne['montant_n1'])->toBeNull()
        ->and((float) $ligne['budget'])->toBe(800.0);

    expect(ligneCompteDuRapport($rapport['charges'], (int) $compte->id))->toBeNull();
});

it('la famille d une enveloppe sans mouvement est creee et porte le libelle des ecritures', function (): void {
    Famille::factory()->create([
        'association_id' => $this->association->id,
        'code' => '61',
        'nom' => 'Services extérieurs',
    ]);

    $compte = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location salle',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 1500.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);

    $famille = collect($rapport['charges'])
        ->first(fn (array $f): bool => $f['famille_nom'] === '61 — Services extérieurs');

    expect($famille)->not->toBeNull()
        // Le sous-total de famille agrège le budget de ses comptes, y compris
        // celui qui n'a aucune écriture.
        ->and((float) $famille['budget'])->toBe(1500.0)
        ->and((float) $famille['montant_n'])->toBe(0.0)
        ->and($famille['comptes'])->toHaveCount(1);
});

it('un compte budgete sans mouvement rejoint la famille de ses homologues mouvementes', function (): void {
    // Le risque réel n'est PAS le repli « (sans famille) » : CompteObserver
    // matérialise une famille pour tout compte de classe 6/7, à la création
    // comme à la renumérotation, si bien que ce repli est inatteignable par
    // l'application (zéro compte concerné en production). Le risque réel est
    // que fetchBudgetRows() et la branche des écritures construisent le
    // LIBELLÉ de famille différemment — la même famille apparaîtrait alors
    // deux fois dans la section, une occurrence par source.
    Famille::factory()->create([
        'association_id' => $this->association->id,
        'code' => '61',
        'nom' => 'Services extérieurs',
    ]);

    $mouvemente = Compte::factory()->numero('613C')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location lieu',
    ]);
    $dormant = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location salle',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $dormant->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 1500.00,
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $mouvemente->id,
        'debit' => 400.00,
        'credit' => 0,
        'montant' => 400.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);

    $familles61 = collect($rapport['charges'])
        ->filter(fn (array $f): bool => $f['famille_nom'] === '61 — Services extérieurs')
        ->values();

    // UNE seule famille, portant les DEUX comptes : c'est ce qui prouve que les
    // deux sources se rejoignent au lieu de se doubler.
    expect($familles61)->toHaveCount(1);

    $famille = $familles61->first();

    expect($famille['comptes'])->toHaveCount(2)
        ->and((float) $famille['montant_n'])->toBe(400.0)
        ->and((float) $famille['budget'])->toBe(1500.0);
});

it('le total budget de chaque section egale la somme des enveloppes de sa classe', function (): void {
    // Une charge mouvementée, une charge budgétée sans mouvement, un produit
    // budgété sans mouvement : le total de chaque section doit contenir les
    // enveloppes des deux natures, et rien de l'autre section.
    $chargeMouvementee = Compte::factory()->numero('627')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Frais bancaires',
    ]);
    $chargeDormante = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location salle',
    ]);
    $produitDormant = Compte::factory()->numero('756')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Mécénat',
    ]);

    foreach ([[$chargeMouvementee, 1000.00], [$chargeDormante, 1500.00], [$produitDormant, 800.00]] as [$compte, $montant]) {
        BudgetLine::factory()->create([
            'association_id' => $this->association->id,
            'compte_id' => $compte->id,
            'exercice' => 2025,
            'operation_id' => null,
            'montant_prevu' => $montant,
        ]);
    }

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $chargeMouvementee->id,
        'debit' => 900.00,
        'credit' => 0,
        'montant' => 900.00,
    ]);

    $vue = Livewire::test(RapportCompteResultat::class)->assertOk();

    expect((float) $vue->viewData('totalChargesBudget'))->toBe(2500.0)
        ->and((float) $vue->viewData('totalProduitsBudget'))->toBe(800.0);
});

it('la ligne budgetee sans mouvement est rendue quel que soit l etat du toggle budget', function (): void {
    $compte = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location salle',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 1500.00,
    ]);

    // Le nombre de lignes du compte de résultat ne doit jamais dépendre d'une
    // bascule d'affichage : le toggle masque des colonnes, pas des lignes.
    $avecBudget = Livewire::test(RapportCompteResultat::class, ['compareBudget' => true])->assertOk();
    $sansBudget = Livewire::test(RapportCompteResultat::class, ['compareBudget' => false])->assertOk();

    expect($avecBudget->html())->toContain('Location salle')
        ->and($sansBudget->html())->toContain('Location salle');
});

it('un compte budgete et mouvemente n apparait qu une fois', function (): void {
    $compte = Compte::factory()->numero('627')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Frais bancaires',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 1000.00,
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => 1300.00,
        'credit' => 0,
        'montant' => 1300.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);

    $occurrences = 0;
    foreach ($rapport['charges'] as $famille) {
        foreach ($famille['comptes'] as $ligne) {
            if ((int) $ligne['compte_id'] === (int) $compte->id) {
                $occurrences++;
            }
        }
    }

    expect($occurrences)->toBe(1);

    $ligne = ligneCompteDuRapport($rapport['charges'], (int) $compte->id);
    expect((float) $ligne['montant_n'])->toBe(1300.0)
        ->and((float) $ligne['budget'])->toBe(1000.0);
});

it('un compte budgete mouvemente seulement en N-1 garde sa ligne de la branche N-1', function (): void {
    $compte = Compte::factory()->numero('627')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Frais bancaires',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 1000.00,
    ]);

    // Exercice 2024 : du 1er septembre 2024 au 31 août 2025. Une date FRANCHEMENT
    // à l'intérieur de la fenêtre — SQLite exclut le dernier jour d'un
    // whereBetween sur des dates nues.
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2024-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => 700.00,
        'credit' => 0,
        'montant' => 700.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);
    $ligne = ligneCompteDuRapport($rapport['charges'], (int) $compte->id);

    // La branche N-1 a créé la ligne ; la branche budget ne l'a pas écrasée,
    // et n'a surtout pas remis montant_n1 à null.
    expect($ligne)->not->toBeNull()
        ->and((float) $ligne['montant_n'])->toBe(0.0)
        ->and((float) $ligne['montant_n1'])->toBe(700.0)
        ->and((float) $ligne['budget'])->toBe(1000.0);
});

it('sans compte budgete dormant la hierarchie est celle d avant', function (): void {
    $compte = Compte::factory()->numero('627')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Frais bancaires',
    ]);

    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => 500.00,
        'credit' => 0,
        'montant' => 500.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat(2025);

    // Aucune enveloppe : aucune ligne fabriquée, et le budget reste null —
    // pas 0.0, ce qui ferait afficher « 0,00 € » au lieu d'un tiret.
    $ligne = ligneCompteDuRapport($rapport['charges'], (int) $compte->id);

    expect($ligne['budget'])->toBeNull()
        ->and($rapport['produits'])->toBe([]);
});

it('l ecart du compte de resultat garde ses couleurs apres passage par ComparaisonBudgetaire', function (): void {
    // Une charge en dépassement (défavorable → cr-neg) et un produit en
    // dépassement (favorable → cr-pos). C'est le couple qui distingue les deux
    // conventions : l'écart brut vaut +300 dans les deux cas, seule la couleur
    // les sépare.
    $charge = Compte::factory()->numero('627')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Frais bancaires',
    ]);
    $produit = Compte::factory()->numero('756')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Mécénat',
    ]);

    foreach ([$charge, $produit] as $compte) {
        BudgetLine::factory()->create([
            'association_id' => $this->association->id,
            'compte_id' => $compte->id,
            'exercice' => 2025,
            'operation_id' => null,
            'montant_prevu' => 1000.00,
        ]);
    }

    $depense = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'compte_id' => $charge->id,
        'debit' => 1300.00,
        'credit' => 0,
        'montant' => 1300.00,
    ]);

    $recette = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'compte_id' => $produit->id,
        'debit' => 0,
        'credit' => 1300.00,
        'montant' => 1300.00,
    ]);

    $html = Livewire::test(RapportCompteResultat::class)->assertOk()->html();

    // Même nombre (+300,00), deux couleurs opposées.
    expect($html)->toContain('<span class="cr-neg">+300,00 &euro;</span>')
        ->and($html)->toContain('<span class="cr-pos">+300,00 &euro;</span>');
});
