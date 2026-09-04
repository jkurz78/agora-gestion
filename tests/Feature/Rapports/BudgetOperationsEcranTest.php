<?php

declare(strict_types=1);

// Deux surfaces, un composant : le rapport du menu et l'onglet de la fiche
// operation, qui n'est que le meme composant avec sa selection pre-remplie.

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
    // Sans cette garde, ComparaisonBudgetaire::ecart() reçoit un budget null
    // coerce a 0.0 (la vue compilee ne porte pas declare(strict_types=1)) et
    // rend +150,00 € en rouge : un compte simplement non ventile se lirait
    // comme un depassement de budget de 150 €, alors qu'il n'a jamais eu de
    // budget auquel se comparer.
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
    expect($html)->toContain('hors dotation');
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
