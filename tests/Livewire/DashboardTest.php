<?php

use App\Livewire\Dashboard;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Famille;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Support\ComparaisonBudgetaire;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
    $this->exercice = 2025;
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('renders for authenticated user', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Solde général')
        ->assertSee('Résumé budget')
        ->assertSee('Dernières dépenses')
        ->assertSee('Dernières recettes')
        ->assertSee('Derniers dons')
        ->assertSee('Dernières adhésions')
        ->assertSee('Opérations')
        ->assertSee('Comptes bancaires')
        ->assertSee('Aucun compte bancaire configuré');
});

it('displays solde general', function () {
    // Le « Solde général » se lit sur le grand livre (classes 6 et 7) et non
    // plus sur montant_total : une transaction sans ligne comptable ne pèse
    // donc rien, ce qui est le comportement voulu — en partie double, toute
    // transaction réelle porte ses lignes. Voir
    // tests/Feature/Immobilisation/SoldeGeneralImmobilisationTest.php pour le
    // défaut qui a motivé le changement (une immobilisation comptée comme une
    // charge).
    $compte706 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '706',
        'classe' => 7,
    ]);
    $compte606 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '606',
        'classe' => 6,
    ]);

    $ligne = function (Transaction $tx, Compte $compte, float $debit, float $credit): void {
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'compte_id' => $compte->id,
            'debit' => $debit,
            'credit' => $credit,
            'montant' => $debit > 0 ? $debit : $credit,
        ]);
    };

    foreach ([[1000.00, '-11-01'], [500.00, '-12-01']] as [$montant, $jour]) {
        $tx = Transaction::factory()->asRecette()->create([
            'association_id' => $this->association->id,
            'date' => $this->exercice.$jour,
            'montant_total' => $montant,
            'saisi_par' => $this->user->id,
        ]);
        $ligne($tx, $compte706, 0.0, $montant);
    }

    $depense = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-15',
        'montant_total' => 300.00,
        'saisi_par' => $this->user->id,
    ]);
    $ligne($depense, $compte606, 300.00, 0.0);

    // Solde = 1000 + 500 - 300 = 1200
    Livewire::test(Dashboard::class)
        ->assertSee('1 200,00');
});

it('shows dernieres adhesions', function () {
    $compteCotisation = Compte::factory()->pourCotisations()->create(['association_id' => $this->association->id]);

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-01',
        'montant_total' => 30.00,
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteCotisation->id,
        'montant' => 30.00,
        'credit' => 30.00,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('30,00');
});

it('displays comptes bancaires with soldes', function () {
    CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Compte Principal',
        'solde_initial' => 1500.00,
        'date_solde_initial' => '2024-01-01',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Compte Principal')
        ->assertSee('1 500,00');
});

it('shows the aucun budget defini message when there is no budget line', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Aucun budget défini pour cet exercice.')
        ->assertDontSee('Résultat prévu');
});

it('budget tile shows a resultat, not the sum of charges and produits envelopes', function () {
    // Piège historique : additionner les enveloppes de classe 6 (charges) et
    // classe 7 (produits) sans les opposer donne un total sans signification
    // comptable. 1000 de charge + 3000 de produit doit donner un résultat
    // prévu de 2000 (3000 - 1000), jamais 4000.
    $compteCharge = Compte::factory()->depense()->create(['association_id' => $this->association->id]);
    $compteProduit = Compte::factory()->recette()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteCharge->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteProduit->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 3000.00,
    ]);

    // Le libellé « Résultat prévu » vivait en un seul bloc avant le point 2
    // du correctif dashboard (décomposition recettes/dépenses) : « Résultat »
    // et « Prévu » sont désormais dans des cellules distinctes d'un tableau.
    Livewire::test(Dashboard::class)
        ->assertSee('Résultat')
        ->assertSee('2 000,00')
        ->assertDontSee('4 000,00');
});

it('budget tile computes resultat realise and ecart from actual bookings', function () {
    $compteCharge = Compte::factory()->depense()->create(['association_id' => $this->association->id]);
    $compteProduit = Compte::factory()->recette()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteCharge->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteProduit->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 3000.00,
    ]);

    $depense = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-15',
        'montant_total' => 800.00,
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'compte_id' => $compteCharge->id,
        'debit' => 800.00,
        'credit' => 0,
        'montant' => 800.00,
    ]);

    $recette = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-11-01',
        'montant_total' => 2500.00,
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'compte_id' => $compteProduit->id,
        'debit' => 0,
        'credit' => 2500.00,
        'montant' => 2500.00,
    ]);

    // Résultat prévu = 3000 - 1000 = 2000 ; résultat réalisé = 2500 - 800 = 1700
    // ; écart = réalisé - prévu = 1700 - 2000 = -300 (en dessous de la prévision).
    Livewire::test(Dashboard::class)
        ->assertSee('2 000,00')
        ->assertSee('1 700,00')
        ->assertSee('-300,00');
});

it('tile realise includes a classe 7 compte with mouvement but no budget envelope', function () {
    // Cœur du point 1 : un compte de classe 7 mouvementé mais sans enveloppe
    // budgétaire doit peser dans le réalisé de la tuile, comme il pèse déjà
    // dans le « Solde général ». Avant correctif, la tuile bouclait sur
    // $budgetLines (les seuls comptes budgétés) au lieu de
    // BudgetService::realiseParCompte() : ce compte sans enveloppe disparaissait
    // silencieusement du résultat affiché.
    $compteBudgete = Compte::factory()->recette()->create(['association_id' => $this->association->id]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteBudgete->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 100.00,
    ]);
    $compteSansEnveloppe = Compte::factory()->recette()->create(['association_id' => $this->association->id]);
    // Pas de BudgetLine pour ce compte : mouvementé mais non budgété.

    $associationId = $this->association->id;
    $userId = $this->user->id;
    $exercice = $this->exercice;
    $encaisser = function (Compte $compte, float $montant, string $jour) use ($associationId, $userId, $exercice) {
        $tx = Transaction::factory()->asRecette()->create([
            'association_id' => $associationId,
            'date' => $exercice.$jour,
            'montant_total' => $montant,
            'saisi_par' => $userId,
        ]);
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'compte_id' => $compte->id,
            'debit' => 0,
            'credit' => $montant,
            'montant' => $montant,
        ]);
    };
    $encaisser($compteBudgete, 50.00, '-10-01');
    $encaisser($compteSansEnveloppe, 400.00, '-10-02');

    // Réalisé attendu = 50 (compte budgété) + 400 (compte sans enveloppe) = 450.
    $resultatRealise = Livewire::test(Dashboard::class)->viewData('resultatRealise');

    expect($resultatRealise)->toBe(450.0);
});

it('tile resultat realise equals solde general with a mix of budgeted and unbudgeted comptes', function () {
    // Point 1 — le contrôle explicite : le réalisé de la tuile doit toujours
    // coïncider avec le « Solde général » du haut de page, qu'il y ait ou non
    // une enveloppe sur chaque compte mouvementé.
    $compteChargeBudgete = Compte::factory()->depense()->create(['association_id' => $this->association->id]);
    $compteProduitBudgete = Compte::factory()->recette()->create(['association_id' => $this->association->id]);
    $compteChargeSansEnveloppe = Compte::factory()->depense()->create(['association_id' => $this->association->id]);
    $compteProduitSansEnveloppe = Compte::factory()->recette()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteChargeBudgete->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 500.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteProduitBudgete->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 900.00,
    ]);

    $associationId = $this->association->id;
    $userId = $this->user->id;
    $exercice = $this->exercice;
    $depenser = function (Compte $compte, float $montant, string $jour) use ($associationId, $userId, $exercice) {
        $tx = Transaction::factory()->asDepense()->create([
            'association_id' => $associationId,
            'date' => $exercice.$jour,
            'montant_total' => $montant,
            'saisi_par' => $userId,
        ]);
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'compte_id' => $compte->id,
            'debit' => $montant,
            'credit' => 0,
            'montant' => $montant,
        ]);
    };
    $encaisser = function (Compte $compte, float $montant, string $jour) use ($associationId, $userId, $exercice) {
        $tx = Transaction::factory()->asRecette()->create([
            'association_id' => $associationId,
            'date' => $exercice.$jour,
            'montant_total' => $montant,
            'saisi_par' => $userId,
        ]);
        TransactionLigne::factory()->create([
            'transaction_id' => $tx->id,
            'compte_id' => $compte->id,
            'debit' => 0,
            'credit' => $montant,
            'montant' => $montant,
        ]);
    };

    $depenser($compteChargeBudgete, 300.00, '-10-01');
    $encaisser($compteProduitBudgete, 700.00, '-10-02');
    $depenser($compteChargeSansEnveloppe, 150.00, '-10-03');
    $encaisser($compteProduitSansEnveloppe, 250.00, '-10-04');

    $component = Livewire::test(Dashboard::class);

    expect($component->viewData('resultatRealise'))->toBe($component->viewData('soldeGeneral'));
    // Valeur explicite, pour ne pas valider une égalité entre deux zéros :
    // solde général = (700 + 250) - (300 + 150) = 500.
    expect($component->viewData('soldeGeneral'))->toBe(500.0);
});

it('tile decomposes recettes and depenses, and resultat is their difference', function () {
    // Point 2 — la tuile doit exposer la décomposition, pas seulement le net.
    $compteProduit = Compte::factory()->recette()->create(['association_id' => $this->association->id]);
    $compteCharge = Compte::factory()->depense()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteProduit->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteCharge->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 400.00,
    ]);

    $recette = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-01',
        'montant_total' => 1200.00,
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'compte_id' => $compteProduit->id,
        'debit' => 0,
        'credit' => 1200.00,
        'montant' => 1200.00,
    ]);

    $depense = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-02',
        'montant_total' => 550.00,
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'compte_id' => $compteCharge->id,
        'debit' => 550.00,
        'credit' => 0,
        'montant' => 550.00,
    ]);

    $component = Livewire::test(Dashboard::class);

    expect($component->viewData('recettesPrevu'))->toBe(1000.0);
    expect($component->viewData('recettesRealise'))->toBe(1200.0);
    expect($component->viewData('depensesPrevu'))->toBe(400.0);
    expect($component->viewData('depensesRealise'))->toBe(550.0);
    expect($component->viewData('resultatPrevu'))->toBe(600.0); // 1000 - 400
    expect($component->viewData('resultatRealise'))->toBe(650.0); // 1200 - 550

    $component
        ->assertSee('Recettes')
        ->assertSee('Dépenses')
        ->assertSee('Résultat')
        ->assertSee('1 000,00')
        ->assertSee('1 200,00')
        ->assertSee('400,00')
        ->assertSee('550,00');
});

it('tile line ecarts add up to the resultat ecart, using the exercice 2025 figures', function () {
    // Point 2, propriété d'additivité — chiffres de l'énoncé, contrôlables à
    // la main. Convention unifiée avec le compte de résultat (2026-09-01) :
    // écart = réalisé - prévu, IDENTIQUE pour une charge et un produit. Ça
    // inverse le signe de l'écart dépenses par rapport à l'ancienne
    // convention (prévu - réalisé côté charge) — et donc l'identité passe
    // d'une ADDITION à une SOUSTRACTION : le résultat est produits MOINS
    // charges, son écart suit la même logique.
    // écart recettes = écart résultat + écart dépenses  ⇔  écart résultat = écart recettes − écart dépenses.
    $compteProduit = Compte::factory()->recette()->create(['association_id' => $this->association->id]);
    $compteCharge = Compte::factory()->depense()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteProduit->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 33148.47,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteCharge->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 21086.07,
    ]);

    $recette = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-01',
        'montant_total' => 32722.48,
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'compte_id' => $compteProduit->id,
        'debit' => 0,
        'credit' => 32722.48,
        'montant' => 32722.48,
    ]);

    $depense = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-02',
        'montant_total' => 33833.47,
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'compte_id' => $compteCharge->id,
        'debit' => 33833.47,
        'credit' => 0,
        'montant' => 33833.47,
    ]);

    $component = Livewire::test(Dashboard::class);

    $ecartRecettes = ComparaisonBudgetaire::ecart(
        $component->viewData('recettesPrevu'),
        $component->viewData('recettesRealise'),
    );
    $ecartDepenses = ComparaisonBudgetaire::ecart(
        $component->viewData('depensesPrevu'),
        $component->viewData('depensesRealise'),
    );
    $ecartResultat = ComparaisonBudgetaire::ecart(
        $component->viewData('resultatPrevu'),
        $component->viewData('resultatRealise'),
    );

    expect(round($ecartRecettes, 2))->toBe(-425.99);
    // Ancienne convention : -12747.40 (prévu - réalisé sur une charge).
    // Nouvelle convention : +12747.40 (réalisé - prévu, comme partout ailleurs).
    expect(round($ecartDepenses, 2))->toBe(12747.40);
    expect(round($ecartResultat, 2))->toBe(-13173.39);
    // Additivité désormais SOUSTRACTIVE : le résultat est produits - charges,
    // donc son écart est écart recettes - écart dépenses (jamais + comme
    // avant, ce qui n'avait de sens que parce que l'écart dépenses portait
    // déjà le signe inversé).
    expect(round($ecartRecettes - $ecartDepenses, 2))->toBe(round($ecartResultat, 2));
});

it('famille table shows a positive ecart when produit realise exceeds prevu', function () {
    // Point 3 — la 8e occurrence du même bug dans ce projet : prévu - réalisé
    // affiché tel quel côté recette, alors qu'il faut réalisé - prévu.
    // 600 prévus / 670 encaissés doit afficher +70, jamais -70 (même si
    // l'ancienne formule bricolée donnait déjà la bonne couleur par accident).
    $famille = Famille::factory()->create([
        'association_id' => $this->association->id,
        'code' => '70',
        'nom' => 'Dons',
    ]);
    $compteProduit = Compte::factory()->numero('706')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Dons',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteProduit->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 600.00,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-01',
        'montant_total' => 670.00,
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteProduit->id,
        'debit' => 0,
        'credit' => 670.00,
        'montant' => 670.00,
    ]);

    $html = Livewire::test(Dashboard::class)->html();

    expect($html)->toContain('text-success">70,00 &euro;</td>');
    expect($html)->not->toContain('-70,00');
});

it('a contra-produit debited reduces resultat realise instead of inflating it', function () {
    $compteProduit = Compte::factory()->recette()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteProduit->id,
        'exercice' => $this->exercice,
        'montant_prevu' => 500.00,
    ]);

    // Contra-produit : le compte de classe 7 est mouvementé au débit (ex. 709
    // Gratuités accordées), ce qui doit réduire le réalisé et non l'augmenter.
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => $this->exercice.'-10-01',
        'montant_total' => 200.00,
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteProduit->id,
        'debit' => 200.00,
        'credit' => 0,
        'montant' => 200.00,
    ]);

    // Réalisé = 0 (crédit) - 200 (débit) = -200 : négatif, pas 200 en valeur
    // absolue ni ignoré.
    Livewire::test(Dashboard::class)
        ->assertSee('-200,00');
});
