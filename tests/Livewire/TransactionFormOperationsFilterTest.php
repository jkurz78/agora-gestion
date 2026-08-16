<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    // Exercice affiché : 2025 (2025-09-01 → 2026-08-31)
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('affiche une opération en cours datée sur un exercice antérieur', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op passée',
        'date_debut' => '2024-09-01',
        'date_fin' => '2025-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertSee('Op passée');
});

it('affiche une opération en cours datée dans un exercice futur', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op future',
        'date_debut' => '2030-09-01',
        'date_fin' => '2031-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertSee('Op future');
});

it('affiche une opération dans l\'exercice courant', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op courante',
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertSee('Op courante');
});

it('n\'affiche pas une opération clôturée même dans l\'exercice', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op clôturée',
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::Cloturee,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertDontSee('Op clôturée');
});

it('affiche à nouveau une opération rouverte', function () {
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op rouverte',
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::Cloturee,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertDontSee('Op rouverte');

    $operation->update(['statut' => StatutOperation::EnCours]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertSee('Op rouverte');

    $operation->update(['statut' => StatutOperation::Cloturee]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertDontSee('Op rouverte');
});

it('affiche une opération en cours hors exercice en recette également', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op recette hors exercice',
        'date_debut' => '2019-09-01',
        'date_fin' => '2020-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->assertSee('Op recette hors exercice');
});

it('la modale de ventilation propose la même opération et exclut une opération clôturée', function () {
    SystemeSeeder::seed();

    $compteVentilation = Compte::factory()->numero('706')->create([
        'association_id' => $this->association->id,
    ]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op ventilation hors exercice',
        'date_debut' => '2019-09-01',
        'date_fin' => '2020-08-31',
        'statut' => StatutOperation::EnCours,
    ]);
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op ventilation clôturée',
        'statut' => StatutOperation::Cloturee,
    ]);

    // Créance (paiementRecu=false) : pas besoin de compte bancaire ni de mode
    // de paiement, seul le grand livre 411/706 doit s'équilibrer.
    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('paiementRecu', false)
        ->set('libelle', 'Recette ventilée pour test modale')
        ->set('tiers_id', $tiers->id)
        ->set('lignes.0.compte_id', (string) $compteVentilation->id)
        ->set('lignes.0.montant', '100.00')
        ->call('save')
        ->assertHasNoErrors();

    $transaction = Transaction::where('libelle', 'Recette ventilée pour test modale')->sole();
    $ligne = $transaction->lignes()->ventilation()->sole();

    // Positionnel : ancre sur le wire:model du select de la modale
    // (affectations.0.operation_id, rendu après celui des lignes directes,
    // lignes.0.operation_id) pour prouver que c'est bien LA MODALE qui
    // affiche l'opération — un assertSee/assertDontSee simple resterait vert
    // même si la modale recréait sa propre condition et se vidait, tant que
    // le select des lignes directes affiche par ailleurs la même opération.
    $html = Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->call('ouvrirVentilation', $ligne->id)
        ->assertSeeHtmlInOrder(['affectations.0.operation_id', 'Op ventilation hors exercice'])
        ->html();

    expect(mb_strpos($html, 'Op ventilation clôturée', mb_strpos($html, 'affectations.0.operation_id')))
        ->toBeFalse();
});
