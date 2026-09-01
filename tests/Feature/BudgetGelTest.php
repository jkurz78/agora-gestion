<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Livewire\BudgetTable;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\Operation;
use App\Models\User;
use App\Services\Budget\BudgetGelService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->admin);

    $this->exerciceAnnee = app(ExerciceService::class)->current();
    // Exercice n'a NI HasFactory NI ExerciceFactory — création directe, comme
    // dans tests/Unit/ExerciceServiceEnrichedTest.php. association_id est posé
    // par l'observer creating de TenantModel.
    $this->exercice = Exercice::create([
        'annee' => $this->exerciceAnnee,
        'statut' => StatutExercice::Ouvert,
    ]);
    $this->compte = Compte::factory()->numero('606')->create(['association_id' => $this->association->id]);
    $this->operation = Operation::factory()->create(['association_id' => $this->association->id]);

    $this->enveloppe = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id, 'exercice' => $this->exerciceAnnee,
        'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);
    $this->ventilation = BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id, 'exercice' => $this->exerciceAnnee,
        'operation_id' => $this->operation->id, 'montant_prevu' => 400.00,
    ]);
});

afterEach(fn () => TenantContext::clear());

it('valide le budget et trace l action', function () {
    app(BudgetGelService::class)->valider($this->exercice, $this->admin);

    expect($this->exercice->fresh()->budgetEstValide())->toBeTrue();
    $this->assertDatabaseHas('exercice_actions', [
        'exercice_id' => $this->exercice->id,
        'action' => TypeActionExercice::BudgetValide->value,
        'user_id' => $this->admin->id,
    ]);
});

it('refuse de modifier une enveloppe apres validation', function () {
    app(BudgetGelService::class)->valider($this->exercice, $this->admin);

    Livewire::test(BudgetTable::class)
        ->call('startEdit', $this->enveloppe->id)
        ->set('editingMontant', '9999')
        ->call('saveEdit');

    expect((float) $this->enveloppe->fresh()->montant_prevu)->toBe(1000.0);
});

it('laisse modifier une ventilation apres validation', function () {
    app(BudgetGelService::class)->valider($this->exercice, $this->admin);

    Livewire::test(BudgetTable::class)
        ->call('startEdit', $this->ventilation->id)
        ->set('editingMontant', '650')
        ->call('saveEdit');

    expect((float) $this->ventilation->fresh()->montant_prevu)->toBe(650.0);
});

it('refuse la suppression d une enveloppe apres validation', function () {
    app(BudgetGelService::class)->valider($this->exercice, $this->admin);

    Livewire::test(BudgetTable::class)->call('deleteLine', $this->enveloppe->id);

    expect(BudgetLine::find($this->enveloppe->id))->not->toBeNull();
});

it('deverrouille avec un commentaire obligatoire et trace l action', function () {
    $service = app(BudgetGelService::class);
    $service->valider($this->exercice, $this->admin);
    $service->deverrouiller($this->exercice, $this->admin, 'Coquille sur le compte 613');

    expect($this->exercice->fresh()->budgetEstValide())->toBeFalse();
    $this->assertDatabaseHas('exercice_actions', [
        'exercice_id' => $this->exercice->id,
        'action' => TypeActionExercice::BudgetDeverrouille->value,
        'commentaire' => 'Coquille sur le compte 613',
    ]);
});

it('refuse un deverrouillage sans commentaire', function () {
    $service = app(BudgetGelService::class);
    $service->valider($this->exercice, $this->admin);

    expect(fn () => $service->deverrouiller($this->exercice, $this->admin, '   '))
        ->toThrow(InvalidArgumentException::class);
});

it('refuse la validation par un non-admin', function () {
    $comptable = User::factory()->create();
    $comptable->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);
    $this->actingAs($comptable);

    Livewire::test(BudgetTable::class)->call('validerBudget');

    expect($this->exercice->fresh()->budgetEstValide())->toBeFalse();
});

// Correctif audit point 4 : valider() et deverrouiller() n'ont aucun verrou —
// un import commencé avant la validation peut se terminer après le gel, et
// deux validations concurrentes produisent deux écritures d'audit. Un test de
// concurrence réelle n'est pas possible ici (process PHP unique) : on vérifie
// à défaut qu'un DOUBLE APPEL séquentiel — le cas dégénéré d'une course, deux
// requêtes qui se chevauchent au point de s'exécuter l'une après l'autre sans
// qu'aucune n'ait rien à re-vérifier — ne produit qu'UNE seule écriture
// d'audit. Le contrôle d'état après verrouillage (reproduit de cloturer())
// est ce qui rend ce test vert.

it('une double validation ne cree qu une seule ecriture d audit', function () {
    $service = app(BudgetGelService::class);

    $service->valider($this->exercice->fresh(), $this->admin);
    $service->valider($this->exercice->fresh(), $this->admin);

    expect(ExerciceAction::where('exercice_id', $this->exercice->id)
        ->where('action', TypeActionExercice::BudgetValide)
        ->count())->toBe(1);
});

it('un deverrouillage sur un budget deja deverrouille ne cree aucune ecriture d audit supplementaire', function () {
    $service = app(BudgetGelService::class);
    $service->valider($this->exercice->fresh(), $this->admin);

    $service->deverrouiller($this->exercice->fresh(), $this->admin, 'Motif 1');
    $service->deverrouiller($this->exercice->fresh(), $this->admin, 'Motif 2');

    expect(ExerciceAction::where('exercice_id', $this->exercice->id)
        ->where('action', TypeActionExercice::BudgetDeverrouille)
        ->count())->toBe(1);
});
