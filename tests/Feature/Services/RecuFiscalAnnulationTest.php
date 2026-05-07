<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('annule un reçu actif', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = $this->ligneDonValide();
    $user = User::factory()->create();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne, $user);

    $service->annuler($recu, 'Adresse corrigée', $user);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->annule_motif)->toBe('Adresse corrigée');
});

it('réémet un reçu : annule l\'ancien et chaîne le nouveau', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = $this->ligneDonValide();
    $user = User::factory()->create();

    $service = app(RecuFiscalService::class);
    $ancien = $service->obtenirOuGenerer($ligne, $user);

    $nouveau = $service->reemettre($ancien, 'Adresse corrigée', $user);
    $ancien->refresh();

    expect($ancien->isAnnule())->toBeTrue();
    expect($ancien->remplace_par_id)->toBe($nouveau->id);
    expect($nouveau->numero)->not->toBe($ancien->numero);
    expect($nouveau->isActif())->toBeTrue();
});
