<?php

use App\Livewire\BudgetAffectationModal;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\User;
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

    $this->exercice = app(ExerciceService::class)->current();
    $this->compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id, 'intitule' => 'Achats non stockés',
    ]);
    $this->opA = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Op A']);
    $this->opB = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Op B']);
});

afterEach(fn () => TenantContext::clear());

it('exclut l operation editee du restant a ventiler', function () {
    // Enveloppe 3 000, A ventilée à 1 000, B à 500.
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 3000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opB->id, 'montant_prevu' => 500.00,
    ]);

    $lignes = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->viewData('lignes');

    $ligne = collect($lignes)->firstWhere('compte_id', (int) $this->compte->id);

    // 3 000 − 500 (opération B seule), et NON 3 000 − 1 500 (A + B, la bogue
    // que ce test doit détecter si l'exclusion de l'opération éditée saute).
    expect($ligne['restant'])->toBe(2500.0)
        ->and($ligne['enveloppe'])->toBe(3000.0)
        ->and($ligne['montant'])->toBe(1000.0);
});

it('accepte une ventilation sur un compte sans enveloppe et ne signale aucun depassement', function () {
    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '750')
        ->call('enregistrer');

    $this->assertDatabaseHas('budget_lines', [
        'compte_id' => $this->compte->id,
        'operation_id' => $this->opA->id,
        'montant_prevu' => '750.00',
    ]);

    $lignes = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->viewData('lignes');
    $ligne = collect($lignes)->firstWhere('compte_id', (int) $this->compte->id);

    expect($ligne['enveloppe'])->toBeNull()
        ->and($ligne['restant'])->toBeNull()
        ->and($ligne['depassement'])->toBe(0.0);
});

it('supprime la ventilation quand la cellule est videe', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 400.00,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '')
        ->call('enregistrer');

    $this->assertDatabaseMissing('budget_lines', [
        'compte_id' => $this->compte->id,
        'operation_id' => $this->opA->id,
    ]);
});

it('enregistre plusieurs comptes en une passe', function () {
    $autre = Compte::factory()->numero('611')->create([
        'association_id' => $this->association->id, 'intitule' => 'Sous-traitance',
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '2000')
        ->set("montants.{$autre->id}", '1800')
        ->call('enregistrer');

    expect(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(2);
});

it('refuse l enregistrement pour un role sans droit d ecriture en compta', function () {
    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);
    $this->actingAs($gestionnaire);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', $this->opA->id)
        ->set("montants.{$this->compte->id}", '999')
        ->call('enregistrer');

    expect(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(0);
});

// Le bouton générique de l'écran Budget dispatche operationId=0 (« aucune
// opération choisie » : le sélecteur reste vide, l'utilisateur en pique une
// dans la modale). Ces deux tests ne viennent pas du plan d'origine — ils
// couvrent la normalisation 0 -> null et le rechargement des montants quand
// l'opération est choisie APRÈS coup via le select, deux chemins que les tests
// fournis (qui appellent tous ouvrir() avec un id déjà connu) ne touchent pas.

it('ouvre sans operation preselectionnee quand operationId vaut zero', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => null, 'montant_prevu' => 3000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opA->id, 'montant_prevu' => 1000.00,
    ]);

    $component = Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->assertSet('operationId', null);

    $ligne = collect($component->viewData('lignes'))->firstWhere('compte_id', (int) $this->compte->id);

    // Aucune opération éditée à exclure : le restant déduit TOUTES les
    // ventilations existantes, comme la sous-ligne « Non affecté » de l'écran
    // Budget.
    expect($ligne['restant'])->toBe(2000.0);
});

it('recharge les montants existants quand on change d operation via le select', function () {
    BudgetLine::factory()->create([
        'association_id' => $this->association->id, 'compte_id' => $this->compte->id,
        'exercice' => $this->exercice, 'operation_id' => $this->opB->id, 'montant_prevu' => 500.00,
    ]);

    Livewire::test(BudgetAffectationModal::class)
        ->call('ouvrir', 0)
        ->assertSet("montants.{$this->compte->id}", null)
        ->set('operationId', $this->opB->id)
        ->assertSet("montants.{$this->compte->id}", '500.00');
});
