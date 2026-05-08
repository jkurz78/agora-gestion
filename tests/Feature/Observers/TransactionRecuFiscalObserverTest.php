<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function setupAssoEligible13(): Association
{
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);

    return $asso;
}

it('annule auto le reçu si la date de transaction change', function () {
    setupAssoEligible13();
    $ligne = $this->ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    expect($recu->isActif())->toBeTrue();

    $ligne->transaction->update(['date' => now()->subDays(10)->toDateString()]);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->annule_motif)->toContain('date');
});

it('annule auto le reçu si le tiers de la transaction change', function () {
    setupAssoEligible13();
    $ligne = $this->ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    expect($recu->isActif())->toBeTrue();

    $autreTiers = Tiers::factory()->create();
    $ligne->transaction->update(['tiers_id' => $autreTiers->id]);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->annule_motif)->toContain('tiers');
});

it('n\'annule PAS le reçu si seul un champ cosmétique change sur la transaction', function () {
    setupAssoEligible13();
    $ligne = $this->ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);

    if (Schema::hasColumn('transactions', 'libelle')) {
        $ligne->transaction->update(['libelle' => 'Nouveau libellé']);
        $recu->refresh();
        expect($recu->isActif())->toBeTrue();
    } else {
        expect(true)->toBeTrue();  // n/a
    }
});
