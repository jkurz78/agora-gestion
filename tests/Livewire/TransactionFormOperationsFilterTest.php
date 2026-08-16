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

/**
 * Monte une recette-créance (paiementRecu=false) avec une ligne sur un compte
 * de ventilation classe 7 : pas besoin de compte bancaire ni de mode de
 * paiement, seul le grand livre 411/706 doit s'équilibrer. Retourne la
 * Transaction persistée.
 */
function creerRecetteCreancePourTestOperations(Compte $compteVentilation, Tiers $tiers, string $libelle, ?Operation $operation = null): Transaction
{
    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('paiementRecu', false)
        ->set('libelle', $libelle)
        ->set('tiers_id', $tiers->id)
        ->set('lignes.0.compte_id', (string) $compteVentilation->id)
        ->set('lignes.0.operation_id', $operation !== null ? (string) $operation->id : '')
        ->set('lignes.0.montant', '100.00')
        ->call('save')
        ->assertHasNoErrors();

    return Transaction::where('libelle', $libelle)->sole();
}

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
    // affiche l'opération — un assertSee simple resterait vert même si la
    // modale recréait sa propre condition et se vidait, tant que le select
    // des lignes directes affiche par ailleurs la même opération.
    // L'assertion négative n'a pas besoin de cet ancrage : l'opération
    // clôturée est exclue des deux sélecteurs, donc absente de tout le HTML.
    Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->call('ouvrirVentilation', $ligne->id)
        ->assertSeeHtmlInOrder(['affectations.0.operation_id', 'Op ventilation hors exercice'])
        ->assertDontSee('Op ventilation clôturée');
});

it('affiche une ligne déjà imputée sur une opération devenue clôturée, avec son select Séance, sans la reproposer ailleurs', function () {
    SystemeSeeder::seed();

    $compteVentilation = Compte::factory()->numero('706')->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op ligne déjà imputée',
        'nombre_seances' => 3,
        'statut' => StatutOperation::EnCours,
    ]);

    // Non référencée : IMP-04 doit continuer à l'exclure partout, avant comme
    // après — c'est la moitié qui a manqué la première fois côté facture.
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op clôturée non référencée ligne',
        'statut' => StatutOperation::Cloturee,
    ]);

    $transaction = creerRecetteCreancePourTestOperations(
        $compteVentilation,
        $tiers,
        'Recette avec opération pour test lignes',
        $operation
    );

    $operation->update(['statut' => StatutOperation::Cloturee]);

    $result = Livewire::test(TransactionForm::class)->call('edit', $transaction->id);

    expect($result->html())
        ->toContain('Op ligne déjà imputée')
        ->toContain('lignes.0.seance')
        ->not->toContain('Op clôturée non référencée ligne');

    $nomsProposes = $result->viewData('operations')->pluck('nom')->all();
    expect($nomsProposes)->not->toContain('Op ligne déjà imputée')
        ->and($nomsProposes)->not->toContain('Op clôturée non référencée ligne');
});

it('la modale de ventilation affiche une opération devenue clôturée déjà affectée, avec son select Séance, sans la reproposer ailleurs', function () {
    SystemeSeeder::seed();

    $compteVentilation = Compte::factory()->numero('706')->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op modale déjà affectée',
        'nombre_seances' => 2,
        'statut' => StatutOperation::EnCours,
    ]);

    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op clôturée non référencée modale',
        'statut' => StatutOperation::Cloturee,
    ]);

    $transaction = creerRecetteCreancePourTestOperations(
        $compteVentilation,
        $tiers,
        'Recette ventilée modale pour opération devenue clôturée'
    );
    $ligne = $transaction->lignes()->ventilation()->sole();

    // L'opération est affectée via la modale, encore en cours à ce stade —
    // exactement le lien métier préexistant que la clôture ne doit pas effacer.
    Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->call('ouvrirVentilation', $ligne->id)
        ->set('affectations.0.operation_id', (string) $operation->id)
        ->set('affectations.0.seance', '1')
        ->set('affectations.0.montant', '100.00')
        ->call('saveVentilation')
        ->assertHasNoErrors();

    $operation->update(['statut' => StatutOperation::Cloturee]);

    $result = Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->call('ouvrirVentilation', $ligne->id);

    // Positionnel comme pour le test « exclut une opération clôturée » ci-dessus :
    // le nom ET le select Séance doivent apparaître après le marqueur de la
    // modale pour prouver que c'est elle qui les affiche, pas les lignes directes.
    $result->assertSeeHtmlInOrder(['affectations.0.operation_id', 'Op modale déjà affectée', 'affectations.0.seance'])
        ->assertDontSee('Op clôturée non référencée modale');

    $nomsProposes = $result->viewData('operations')->pluck('nom')->all();
    expect($nomsProposes)->not->toContain('Op modale déjà affectée')
        ->and($nomsProposes)->not->toContain('Op clôturée non référencée modale');
});
