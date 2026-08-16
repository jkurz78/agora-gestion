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

it('la fiche facture garde affichée une opération clôturée déjà imputée sur sa ligne sans la proposer ailleurs', function () {
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

    // Deuxième ligne vierge sur la même facture : c'est elle qui expose la
    // fuite IMP-04 si l'opération clôturée réapparaît comme option
    // choisissable en plus de son propre affichage sur la ligne 1.
    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Ligne vierge',
        'montant' => 50.00,
        'ordre' => 2,
        'operation_id' => null,
    ]);

    $component = Livewire::test(FactureEdit::class, ['facture' => $facture]);

    // IMP-04 à la lettre : la liste PROPOSÉE n'inclut jamais une opération
    // clôturée, imputée ou non.
    $nomsProposes = $component->viewData('operations')->pluck('nom')->all();
    expect($nomsProposes)->not->toContain('Op clôturée déjà imputée')
        ->and($nomsProposes)->not->toContain('Op clôturée non utilisée');

    // IMP-02 : le lien préexistant (ligne 1) reste affiché malgré la clôture.
    expect($component->html())->toContain('Op clôturée déjà imputée');

    // La preuve que ce n'est PAS une proposition : une seule occurrence dans
    // tout le HTML — celle de la ligne 1 — alors que la ligne 2 vierge et le
    // formulaire de nouvelle ligne partagent tous deux la même source
    // `$operations`.
    expect(substr_count($component->html(), 'Op clôturée déjà imputée'))->toBe(1);
});
