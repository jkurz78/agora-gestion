<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\StatutOperation;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

// IMP-02 — invariant central : le statut clôturé filtre des propositions, il
// n'interdit aucune écriture. Le filet PRINCIPAL est comportemental et vit dans
// le test « accepte un operation_id clôturé du bon tenant » plus bas : il
// traverse la validation, le service et la persistance, et meurt sous une garde
// de statut plantée n'importe où sur ce trajet.
//
// Le test ci-dessous n'est qu'un filet secondaire : il attrape la formulation
// nommant explicitement l'enum. Une garde écrite `!== StatutOperation::EnCours`
// lui échapperait — ne pas s'y fier seul.
it('TransactionService ne refuse pas une opération clôturée', function () {
    $reflection = new ReflectionClass(TransactionService::class);

    expect($reflection->getFileName())->not->toBeNull();
    expect(file_get_contents($reflection->getFileName()))
        ->not->toContain('StatutOperation::Cloturee');
});

it('refuse un operation_id appartenant à une autre association', function () {
    $autre = Association::factory()->create();
    $opAutre = Operation::factory()->create([
        'association_id' => $autre->id,
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('lignes.0.operation_id', (string) $opAutre->id)
        ->call('save')
        ->assertHasErrors('lignes.0.operation_id');
});

it('refuse un operation_id d\'une autre association dans la modale de ventilation', function () {
    SystemeSeeder::seed();

    $compteVentilation = Compte::factory()->numero('706')->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $autre = Association::factory()->create();
    $opAutre = Operation::factory()->create([
        'association_id' => $autre->id,
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('paiementRecu', false)
        ->set('libelle', 'Recette pour test isolation modale')
        ->set('tiers_id', $tiers->id)
        ->set('lignes.0.compte_id', (string) $compteVentilation->id)
        ->set('lignes.0.montant', '100.00')
        ->call('save')
        ->assertHasNoErrors();

    $transaction = Transaction::where('libelle', 'Recette pour test isolation modale')->sole();
    $ligne = $transaction->lignes()->ventilation()->sole();

    Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->call('ouvrirVentilation', $ligne->id)
        ->set('affectations.0.operation_id', (string) $opAutre->id)
        ->set('affectations.0.montant', (string) $ligne->montant)
        ->call('saveVentilation')
        ->assertHasErrors('affectations.0.operation_id');
});

it('accepte un operation_id clôturé du bon tenant sans aucune erreur de validation', function () {
    // Critère d'acceptation 4 / cœur d'IMP-02 : la règle exists scopée au
    // tenant ne doit jamais se confondre avec une garde de statut. Dette
    // (paiementRecu=false) : dispense mode_paiement/dateReglement, pour
    // isoler la validation sur operation_id et ne recevoir AUCUNE erreur.
    SystemeSeeder::seed();

    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'statut' => StatutOperation::Cloturee,
    ]);
    $compteDepense = Compte::factory()->depense()->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('paiementRecu', false)
        ->set('tiers_id', $tiers->id)
        ->set('lignes.0.compte_id', (string) $compteDepense->id)
        ->set('lignes.0.operation_id', (string) $operation->id)
        ->set('lignes.0.montant', '50.00')
        ->call('save')
        ->assertHasNoErrors();
});

it('affiche visiblement le refus quand la date tombe sur un exercice clôturé', function () {
    // À l'origine, la règle de validation refusait toute date sortant de
    // l'exercice AFFICHÉ, et son message était rendu dans un .invalid-feedback
    // que Bootstrap masque tant qu'un frère précédent ne porte pas
    // .is-invalid — ce que x-date-input ne fait jamais. L'écran bloquait donc
    // sans rien expliquer.
    //
    // Cette borne d'exercice affiché a depuis été retirée (deux exercices
    // ouverts se chevauchent plusieurs mois par an) : seule la clôture de
    // l'exercice DE LA DATE refuse désormais la saisie. Le test est donc
    // reconstruit sur un exercice réellement clôturé — il continue de
    // verrouiller la VISIBILITÉ du refus, pas seulement sa présence.
    SystemeSeeder::seed();
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Cloture,
    ]);
    $compteDepense = Compte::factory()->depense()->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $html = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('paiementRecu', false)
        ->set('date', '2025-01-15')
        ->set('libelle', 'Facture datée par erreur')
        ->set('tiers_id', $tiers->id)
        ->set('lignes.0.compte_id', (string) $compteDepense->id)
        ->set('lignes.0.montant', '100.00')
        ->call('save')
        ->assertHasErrors('date')
        ->html();

    expect($html)->toContain('invalid-feedback d-block')
        ->and($html)->toContain('clôturé');

    expect(Transaction::count())->toBe(0);
});
