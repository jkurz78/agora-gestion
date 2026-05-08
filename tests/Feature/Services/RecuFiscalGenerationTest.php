<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function setupAssoEligible(): Association
{
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    return $asso;
}

it('génère un reçu fiscal pour un don valide', function () {
    setupAssoEligible();
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu)->toBeInstanceOf(RecuFiscalEmis::class);
    expect($recu->numero)->toBe('2026-0001');
    expect($recu->annee_civile)->toBe((int) $ligne->transaction->date->format('Y'));
    expect($recu->tiers_id)->toBe($ligne->transaction->tiers_id);
    expect($recu->transaction_ligne_id)->toBe($ligne->id);
    expect($recu->montant_centimes)->toBe((int) round((float) $ligne->montant * 100));
    expect($recu->pdf_hash)->toHaveLength(64);
    expect(Storage::disk('local')->exists($recu->pdfFullPath()))->toBeTrue();
});

it('est idempotent : un deuxième appel retourne le même reçu', function () {
    setupAssoEligible();
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu1 = $service->obtenirOuGenerer($ligne);
    $recu2 = $service->obtenirOuGenerer($ligne);

    expect($recu2->id)->toBe($recu1->id);
    expect($recu2->numero)->toBe($recu1->numero);
    expect(RecuFiscalEmis::count())->toBe(1);
});

it('dérive article 200 pour un donateur particulier', function () {
    setupAssoEligible();
    $ligne = $this->ligneDonValide(tiersOverrides: ['type' => 'particulier']);

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu->article_cgi)->toBe('art_200');
});

it('dérive article 238 bis pour un donateur entreprise', function () {
    setupAssoEligible();
    $ligne = $this->ligneDonValide(tiersOverrides: ['type' => 'entreprise', 'entreprise' => 'Acme SAS']);

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu->article_cgi)->toBe('art_238_bis');
});

it('dérive forme abandon_revenus pour sous-cat avec usage AbandonCreance', function () {
    setupAssoEligible();

    // Créer une sous-cat avec les deux usages Don + AbandonCreance
    $sousCatAbandon = SousCategorie::factory()->create();
    $sousCatAbandon->usages()->create(['usage' => UsageComptable::Don->value]);
    $sousCatAbandon->usages()->create(['usage' => UsageComptable::AbandonCreance->value]);

    $ligne = $this->ligneDonValide(ligneOverrides: ['sous_categorie_id' => $sousCatAbandon->id]);

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu->forme_don)->toBe('abandon_revenus');
});

it('vérifie que pdf_hash correspond bien au binaire stocké', function () {
    setupAssoEligible();
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu->verifierIntegrite())->toBeTrue();
});
