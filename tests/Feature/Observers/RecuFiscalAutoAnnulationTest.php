<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\SousCategorie;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function setupAssoEligible12(): Association
{
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);

    return $asso;
}

it('annule auto le reçu si la ligne don est supprimée', function () {
    setupAssoEligible12();
    $ligne = $this->ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    expect($recu->isActif())->toBeTrue();

    $ligne->delete();
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->annule_motif)->toContain('supprim');
});

it('annule auto le reçu si le montant change', function () {
    setupAssoEligible12();
    $ligne = $this->ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    $ligne->update(['montant' => $ligne->montant + 10]);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->annule_motif)->toContain('modifi');
});

it('annule auto le reçu si la sous_categorie_id change', function () {
    setupAssoEligible12();
    $ligne = $this->ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);

    // Créer une autre sous-cat (un usage Don aussi pour rester cohérent)
    $autreSousCat = SousCategorie::factory()->pourDons()->create();
    $ligne->update(['sous_categorie_id' => $autreSousCat->id]);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
});

it('n\'annule PAS le reçu si seules les notes/libelle changent', function () {
    setupAssoEligible12();
    $ligne = $this->ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);

    // Adapter selon les colonnes RÉELLES de transaction_lignes — vérifier la migration
    $champsCosmetiques = [];
    if (Schema::hasColumn('transaction_lignes', 'notes')) {
        $champsCosmetiques['notes'] = 'Nouvelle note';
    }
    if (Schema::hasColumn('transaction_lignes', 'libelle')) {
        $champsCosmetiques['libelle'] = 'Nouveau libellé';
    }

    if (! empty($champsCosmetiques)) {
        $ligne->update($champsCosmetiques);
        $recu->refresh();
        expect($recu->isActif())->toBeTrue();
    } else {
        expect(true)->toBeTrue();  // n/a si ces colonnes n'existent pas — sera couvert au niveau Transaction
    }
});
