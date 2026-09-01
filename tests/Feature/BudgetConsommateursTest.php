<?php

use App\Livewire\BudgetTable;
use App\Livewire\Dashboard;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\BudgetExportService;
use App\Services\ClotureCheckService;
use App\Services\ExerciceService;
use App\Services\RapportService;
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
        'association_id' => $this->association->id,
        'intitule' => 'Achats non stockés',
    ]);
    $this->operation = Operation::factory()->create(['association_id' => $this->association->id]);

    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id, 'exercice' => $this->exercice,
        'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id, 'exercice' => $this->exercice,
        'operation_id' => $this->operation->id, 'montant_prevu' => 400.00,
    ]);
});

afterEach(fn () => TenantContext::clear());

it('scope enveloppes ne rend que la ligne non ventilee', function () {
    expect(BudgetLine::forExercice($this->exercice)->enveloppes()->count())->toBe(1)
        ->and((float) BudgetLine::forExercice($this->exercice)->enveloppes()->sum('montant_prevu'))->toBe(1000.0);
});

it('scope ventilations ne rend que les lignes rattachees a une operation', function () {
    expect(BudgetLine::forExercice($this->exercice)->ventilations()->count())->toBe(1)
        ->and((float) BudgetLine::forExercice($this->exercice)->ventilations()->sum('montant_prevu'))->toBe(400.0);
});

it('le dashboard ne double ni le prevu ni le realise', function () {
    $vue = Livewire::test(Dashboard::class)->viewData('budgetParFamille');
    $totalPrevu = array_sum(array_column($vue, 'prevu'));

    expect($totalPrevu)->toBe(1000.0);
});

it('le compte de resultat ne double pas la colonne budget', function () {
    // fetchBudgetMap n'attache 'budget' qu'aux comptes déjà présents dans la
    // hiérarchie (charges/produits construits depuis le grand livre) : un
    // compte qui n'a que des lignes de budget, sans aucun mouvement, n'y
    // apparaît jamais. Mouvement minimal indispensable, même s'il ne compte
    // pas dans le scénario "enveloppe vs ventilation" — cf. le même motif
    // dans tests/Unit/RapportServiceTest.php.
    $depense = Transaction::factory()->asDepense()->create(['saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'compte_id' => $this->compte->id,
        'montant' => 100.00,
        'debit' => 100.00,
        'credit' => 0.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultat($this->exercice);
    $budgets = [];
    array_walk_recursive($rapport, function ($v, $k) use (&$budgets) {
        if ($k === 'budget') {
            $budgets[] = (float) $v;
        }
    });

    expect(max($budgets))->toBe(1000.0);
});

it('l export porte l enveloppe et non la ventilation', function () {
    $rows = app(BudgetExportService::class)->rows($this->exercice, 'budget', $this->exercice);
    $ligne = collect($rows)->firstWhere(2, 'Achats non stockés');

    expect($ligne[3])->toBe('1000.00');
});

it('la gate de cloture compte les enveloppes', function () {
    // API réelle : executer(int $annee): ClotureCheckResult, qui expose
    // ->bloquants et ->avertissements, deux listes de CheckItem readonly.
    $resultat = app(ClotureCheckService::class)->executer($this->exercice);
    $budget = collect($resultat->bloquants)
        ->merge($resultat->avertissements)
        ->firstWhere('nom', 'Budget absent');

    expect($budget)->not->toBeNull()
        ->and($budget->message)->toContain('1 ligne');
});

it('l ecran budget affiche l enveloppe en ligne de compte', function () {
    Livewire::test(BudgetTable::class)
        ->assertOk()
        ->assertSee('1 000,00');
});
