<?php

use App\Livewire\BudgetTable;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\SousCategorie;
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

    $this->depenseCategorie = Categorie::factory()->depense()->create(['association_id' => $this->association->id, 'nom' => 'Cat Depense']);
    $this->depenseSc = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->depenseCategorie->id,
        'nom' => 'SC Depense',
    ]);

    $this->recetteCategorie = Categorie::factory()->recette()->create(['association_id' => $this->association->id, 'nom' => 'Cat Recette']);
    $this->recetteSc = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->recetteCategorie->id,
        'nom' => 'SC Recette',
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
        ->call('addLine', $this->depenseSc->id);

    $this->assertDatabaseHas('budget_lines', [
        'sous_categorie_id' => $this->depenseSc->id,
        'exercice' => $exercice,
        'montant_prevu' => '0.00',
    ]);
});

it('can edit montant_prevu inline', function () {
    $exercice = app(ExerciceService::class)->current();

    $line = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'sous_categorie_id' => $this->depenseSc->id,
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
        'sous_categorie_id' => $this->depenseSc->id,
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
        'sous_categorie_id' => $this->depenseSc->id,
        'exercice' => $exercice,
        'montant_prevu' => 1000.00,
    ]);

    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('1 000,00');
});
