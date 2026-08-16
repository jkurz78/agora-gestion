<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Enums\TypeLigneFacture;
use App\Livewire\FactureEdit;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Operation;
use App\Models\User;
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

it('la fiche facture ne propose pas une opération clôturée', function () {
    $facture = Facture::factory()->create(['association_id' => $this->association->id]);

    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op clôturée facture',
        'statut' => StatutOperation::Cloturee,
    ]);
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op en cours facture',
        'date_debut' => '2019-09-01',
        'date_fin' => '2020-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    $noms = Livewire::test(FactureEdit::class, ['facture' => $facture])
        ->viewData('operations')
        ->pluck('nom')
        ->all();

    expect($noms)->toContain('Op en cours facture')
        ->and($noms)->not->toContain('Op clôturée facture');
});

it('la fiche facture garde visible une opération clôturée déjà imputée sur une de ses lignes', function () {
    $facture = Facture::factory()->create(['association_id' => $this->association->id]);

    $operationImputee = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op clôturée déjà imputée',
        'statut' => StatutOperation::Cloturee,
    ]);

    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op clôturée non utilisée',
        'statut' => StatutOperation::Cloturee,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Ligne déjà imputée',
        'montant' => 100.00,
        'ordre' => 1,
        'operation_id' => $operationImputee->id,
    ]);

    $noms = Livewire::test(FactureEdit::class, ['facture' => $facture])
        ->viewData('operations')
        ->pluck('nom')
        ->all();

    expect($noms)->toContain('Op clôturée déjà imputée')
        ->and($noms)->not->toContain('Op clôturée non utilisée');
});
