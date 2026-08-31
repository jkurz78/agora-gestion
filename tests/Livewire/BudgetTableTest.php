<?php

use App\Enums\StatutExercice;
use App\Livewire\BudgetTable;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\User;
use App\Services\Budget\BudgetGelService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // L'écran budget est clé par compte : création directe des comptes affichés.
    $this->depenseCompte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'SC Depense',
    ]);

    $this->recetteCompte = Compte::factory()->numero('706')->create([
        'association_id' => $this->association->id,
        'intitule' => 'SC Recette',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('renders with exercice', function () {
    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Charges')
        ->assertSee('Produits')
        ->assertSee('SC Depense')
        ->assertSee('SC Recette');
});

it('can add a budget line', function () {
    $exercice = app(ExerciceService::class)->current();

    Livewire::test(BudgetTable::class)
        ->call('addLine', $this->depenseCompte->id);

    $this->assertDatabaseHas('budget_lines', [
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice,
        'montant_prevu' => '0.00',
    ]);
});

it('can edit montant_prevu inline', function () {
    $exercice = app(ExerciceService::class)->current();

    $line = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice,
        'montant_prevu' => 100.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('startEdit', $line->id)
        ->assertSet('editingLineId', $line->id)
        ->assertSet('editingMontant', '100.00')
        ->set('editingMontant', '250.00')
        ->call('saveEdit')
        ->assertSet('editingLineId', null);

    $this->assertDatabaseHas('budget_lines', [
        'id' => $line->id,
        'montant_prevu' => '250.00',
    ]);
});

it('can delete a budget line', function () {
    $exercice = app(ExerciceService::class)->current();

    $line = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice,
        'montant_prevu' => 500.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->call('deleteLine', $line->id);

    $this->assertDatabaseMissing('budget_lines', ['id' => $line->id]);
});

it('shows prevu vs realise', function () {
    $exercice = app(ExerciceService::class)->current();

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice,
        'montant_prevu' => 1000.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('1 000,00');
});

it('affiche les ventilations en sous-lignes du compte', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Stage été 2026',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 3500.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 2000.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Stage été 2026')
        ->assertSee('3 500,00')
        ->assertSee('2 000,00')
        ->assertSee('Non affecté')
        ->assertSee('1 500,00');
});

it('signale un depassement engage avant tout realise', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Stage coûteux',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 1500.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 1800.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Dépassement engagé')
        ->assertSee('-300,00');
});

it('remonte les operations ouvertes sans budget affecte', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Atelier orphelin',
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('sans budget affecté')
        ->assertSee('Atelier orphelin');
});

it('ne remonte pas une operation deja budgetee', function () {
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Atelier budgété',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->depenseCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 500.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertDontSee('Atelier budgété n\'a aucun budget');
});

it('affiche aussi les ventilations en sous-lignes sur un compte de produits', function () {
    // Le blade a deux blocs symétriques (Charges / Produits) : ce test protège
    // spécifiquement le bloc Produits, qui n'est couvert par aucun autre test
    // du plan — un oubli de recopie y passerait inaperçu.
    $exercice = app(ExerciceService::class)->current();
    $op = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Gala annuel',
    ]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->recetteCompte->id,
        'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 4000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->recetteCompte->id,
        'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 2500.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Gala annuel')
        ->assertSee('4 000,00')
        ->assertSee('2 500,00')
        ->assertSee('Non affecté')
        ->assertSee('1 500,00');
});

it('affiche le bandeau de validation du budget quand il n\'est pas encore valide', function () {
    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('n\'est pas encore validé', false)
        ->assertSee('Valider le budget');
});

it('affiche le bandeau de budget valide avec le nom du validateur', function () {
    // Exercice n'a NI HasFactory NI ExerciceFactory — création directe, comme
    // dans tests/Feature/BudgetGelTest.php.
    $exercice = Exercice::create([
        'annee' => app(ExerciceService::class)->current(),
        'statut' => StatutExercice::Ouvert,
    ]);
    app(BudgetGelService::class)->valider($exercice, $this->user);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('Budget validé le')
        ->assertSee($this->user->name)
        ->assertSee('Déverrouiller');
});

// Correctif 2 (revue BudgetAffectationModal) : contrairement au bouton
// générique juste au-dessus, ce bandeau n'était gardé ni par $exerciceCloture
// ni par canEdit. Sur un exercice clôturé, ses badges ouvraient la modale
// d'affectation, saisie possible, et Enregistrer levait une 500 (Exercice
// CloturedException nue, sans handler).
it('cache le bandeau operations sans budget affecte quand l exercice est cloture', function () {
    Exercice::create([
        'annee' => app(ExerciceService::class)->current(),
        'statut' => StatutExercice::Cloture,
    ]);
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id, 'nom' => 'Op sans budget',
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertDontSee('sans budget affecté')
        ->assertDontSee($operation->nom);
});
