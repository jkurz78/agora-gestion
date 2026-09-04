<?php

declare(strict_types=1);

// Deux surfaces, un composant : le rapport du menu et l'onglet de la fiche
// operation, qui n'est que le meme composant avec sa selection pre-remplie.

use App\Livewire\OperationDetail;
use App\Livewire\RapportBudgetOperations;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
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

it('l onglet d une operation ventilee sans mouvement affiche son budget', function (): void {
    // Sans le drapeau avecBudget de la Task 1, normaliser() viderait la
    // selection et l'onglet s'afficherait vide sur sa propre fiche.
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Stage de printemps',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [(int) $operation->id]])
        ->assertOk()
        ->assertSet('selectionIgnoree', false)
        ->assertSee('Fournitures')
        ->assertSee('300,00')
        // Ce compte est budgete ET n'a aucun mouvement : le marqueur ne se
        // rattache qu'au realise (cf. BudgetOperationBuilder::parOperations()),
        // donc pas de badge ici — sans quoi TOUT compte porterait le badge.
        ->assertDontSee('hors dotation');
});

it('sans selection l ecran invite a choisir une operation', function (): void {
    Livewire::test(RapportBudgetOperations::class)
        ->assertOk()
        ->assertSet('selectionIgnoree', false);
});

it('une selection entierement inconnue est signalee, pas silencieuse', function (): void {
    Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [999999]])
        ->assertOk()
        ->assertSet('selectionIgnoree', true);
});

it('un compte sans prevision rend une case vide et non un zero', function (): void {
    $compte = Compte::factory()->numero('741')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Subventions publiques',
        'classe' => 7,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 1200.00,
    ]);

    $html = Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [(int) $operation->id]])
        ->assertOk()
        ->html();

    // La ligne existe et porte son budget…
    expect($html)->toContain('Subventions publiques');
    expect($html)->toContain('1 200,00');
    // …et la cellule de prevision porte la classe du vide, pas un montant.
    expect($html)->toContain('budget-op-vide');
});

it('une legende dit ce que le previsionnel couvre', function (): void {
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [(int) $operation->id]])
        ->assertSee('règlements des participants');
});

it('un compte hors dotation n a pas de budget de reference, son ecart reste vide', function (): void {
    // Aucun des tests precedents ne pose un compte dont le budget est null
    // ALORS QUE son realise est non nul (hors dotation) : ils ne peuvent donc
    // pas tuer un retrait de la garde `$budget === null` dans $renderEcart.
    // Sans cette garde, ComparaisonBudgetaire::ecart(float $prevu, float $realise)
    // recevrait null sur un parametre float non nullable : le mode coercitif
    // de PHP ne convertit pas null en 0.0 dans ce cas, meme si l'appelant (la
    // vue compilee) ne porte pas declare(strict_types=1) — c'est une
    // TypeError, la page plante en 500. La garde reste necessaire, mais pas
    // pour eviter un affichage trompeur : elle evite un plantage. La case
    // vide est simplement le bon rendu.
    $compte = Compte::factory()->numero('625B')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Deplacements',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 150.00,
        'credit' => 0,
    ]);

    $html = Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [(int) $operation->id]])
        ->assertOk()
        ->html();

    expect($html)->toContain('Deplacements');
    // Les deux surfaces qui portent la sous-chaine « hors dotation » sont
    // verifiees separement : le badge sur la ligne de compte, et la ligne
    // de total « dont hors dotation ». Chercher seulement 'hors dotation'
    // laisse l'une des deux muette a une suppression — chacune contient la
    // sous-chaine de l'autre.
    expect($html)->toContain('badge text-bg-secondary ms-1">hors dotation');
    expect($html)->toContain('dont hors dotation');
    // Le realise (150 €) est bien affiche...
    expect($html)->toContain('150,00');
    // ...mais aucune colonne Ecart ne porte de valeur calculee : sans budget
    // de reference, il n'y a rien a comparer. class="cr-neg"/cr-pos/cr-zero
    // ne sont poses sur un <span> que par la branche "budget non null" de
    // $renderEcart — le <style> du haut de page cite ces memes noms de
    // classe dans ses selecteurs, d'ou la recherche de l'attribut posé, pas
    // du simple nom.
    expect($html)->not->toContain('class="cr-neg"');
    expect($html)->not->toContain('class="cr-pos"');
    expect($html)->not->toContain('class="cr-zero"');
});

// Le sens de l'ecart : aucun des tests ci-dessus ne pose un budget non nul ET
// un realise non nul, donc aucun n'observe jamais un ecart reellement
// calcule. C'est le defaut corrige 8 fois sur 3 ecrans de ce projet — routage
// par ComparaisonBudgetaire::ecart($budget, $realise) et par $isCharge.

it('une charge en depassement de budget rend un ecart positif et defavorable', function (): void {
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);

    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 500.00,
        'credit' => 0,
    ]);

    $html = Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [(int) $operation->id]])
        ->assertOk()
        ->html();

    // Depense 500 pour un budget de 300 : ecart = realise - budget = +200,
    // et une charge qui depasse son budget est defavorable.
    expect($html)->toContain('+200,00');
    expect($html)->toContain('class="cr-neg"');
});

it('une recette sous realisee rend un ecart negatif et defavorable', function (): void {
    $compte = Compte::factory()->numero('7061')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Cotisations',
        'classe' => 7,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 500.00,
    ]);

    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 0,
        'credit' => 300.00,
    ]);

    $html = Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [(int) $operation->id]])
        ->assertOk()
        ->html();

    // Encaisse 300 pour un budget de 500 : ecart = realise - budget = -200,
    // et une recette sous son budget est defavorable — sens oppose a la
    // charge ci-dessus pour le meme ecart absolu.
    expect($html)->toContain('-200,00');
    expect($html)->toContain('class="cr-neg"');
});

it('une recette sur realisee rend un ecart positif et favorable', function (): void {
    $compte = Compte::factory()->numero('7062')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Dons manuels',
        'classe' => 7,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 500.00,
    ]);

    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'debit' => 0,
        'credit' => 700.00,
    ]);

    $html = Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [(int) $operation->id]])
        ->assertOk()
        ->html();

    // Encaisse 700 pour un budget de 500 : ecart = realise - budget = +200,
    // et une recette au-dela de son budget est favorable.
    expect($html)->toContain('+200,00');
    expect($html)->toContain('class="cr-pos"');
});

it('le rendu de deux operations selectionnees affiche un titre par operation', function (): void {
    $compteA = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'classe' => 6,
    ]);
    $compteB = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'classe' => 6,
    ]);
    $operationA = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Stage de printemps',
    ]);
    $operationB = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Sortie automne',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteA->id,
        'operation_id' => $operationA->id,
        'exercice' => 2025,
        'montant_prevu' => 100.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compteB->id,
        'operation_id' => $operationB->id,
        'exercice' => 2025,
        'montant_prevu' => 200.00,
    ]);

    Livewire::test(RapportBudgetOperations::class, [
        'selectedOperationIds' => [(int) $operationA->id, (int) $operationB->id],
    ])
        ->assertOk()
        ->assertSee('<h5', false)
        ->assertSee('Stage de printemps')
        ->assertSee('Sortie automne');
});

it('une operation sans depense ne rend aucun total cote depenses', function (): void {
    $compte = Compte::factory()->numero('7061')->create([
        'association_id' => $this->association->id,
        'classe' => 7,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 400.00,
    ]);

    $html = Livewire::test(RapportBudgetOperations::class, ['selectedOperationIds' => [(int) $operation->id]])
        ->assertOk()
        ->html();

    expect($html)->toContain('Aucun compte.');
    expect($html)->not->toContain('TOTAL DÉPENSES');
});

it('un id inconnu mele a un id valide n empeche pas le rapport et ne declenche pas le bandeau', function (): void {
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures diverses',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 250.00,
    ]);

    Livewire::test(RapportBudgetOperations::class, [
        'selectedOperationIds' => [(int) $operation->id, 999999],
    ])
        ->assertOk()
        ->assertSet('selectionIgnoree', false)
        ->assertSee('Fournitures diverses');
});

// ── Rendre l'ecran atteignable : route, entree de menu, onglet de la fiche
// operation. Les tests ci-dessus exercent le composant en isolation ; ceux-ci
// exercent le cablage qui l'expose (Task 7).

it('la route de l ecran budget par operations repond', function (): void {
    $this->get('/rapports/budget-operations')->assertOk();
});

it('la route de l ecran budget par operations est fermee a un visiteur non authentifie', function (): void {
    auth()->logout();

    $this->get('/rapports/budget-operations')
        ->assertRedirect(route('login'));
});

it('l onglet budget de la fiche operation embarque le budget de cette operation precise', function (): void {
    // Test de cablage, pas de comportement du composant (deja couvert
    // ci-dessus) : il doit tomber si l'onglet passe un :selectedOperationIds
    // vide ou errone a <livewire:rapport-budget-operations>. Assertion sur un
    // intitule de compte et un montant formes, pas sur un simple mot comme
    // « budget » : ce dernier apparaitrait aussi dans le libelle de l'onglet
    // lui-meme, meme si le composant sous-jacent recevait une selection vide.
    $compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures atelier poterie',
        'classe' => 6,
    ]);
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Atelier poterie',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'operation_id' => $operation->id,
        'exercice' => 2025,
        'montant_prevu' => 456.78,
    ]);

    Livewire::test(OperationDetail::class, ['operation' => $operation])
        ->set('activeTab', 'budget')
        ->assertSee('Fournitures atelier poterie')
        ->assertSee('456,78');
});
