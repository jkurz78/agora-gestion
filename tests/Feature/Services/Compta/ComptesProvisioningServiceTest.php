<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Services\Compta\ComptesProvisioningService;
use App\Tenant\TenantContext;

// Régression du blocant #2 : les seeders compta tournent dans les migrations
// AVANT que les tables source soient peuplées. ComptesProvisioningService les
// rejoue une fois les données présentes pour que `comptes` soit non vide.

test('provisionAll seede les comptes systeme, bancaires et de gestion une fois les sources presentes', function () {
    $assoId = TenantContext::currentId();

    // Source 1 : un compte de gestion (classe 7).
    Compte::factory()->numero('706')->create(['intitule' => 'Ventes']);

    // Source 2 : un compte bancaire.
    $bancaire = CompteBancaire::factory()->create(['association_id' => $assoId]);

    expect(Compte::where('numero_pcg', '706')->exists())->toBeTrue();

    app(ComptesProvisioningService::class)->provisionAll();

    // Comptes système toujours présents.
    expect(Compte::where('numero_pcg', '411')->where('est_systeme', true)->exists())->toBeTrue();
    expect(Compte::where('numero_pcg', '401')->where('est_systeme', true)->exists())->toBeTrue();
    expect(Compte::where('numero_pcg', '5112')->where('est_systeme', true)->exists())->toBeTrue();

    // Compte de gestion dérivé de le compte.
    $gestion = Compte::where('numero_pcg', '706')->first();
    expect($gestion)->not->toBeNull();
    expect((int) $gestion->classe)->toBe(7);

    // Compte bancaire 512X relié par compte_bancaire_id.
    $bancaireCompte = Compte::where('compte_bancaire_id', $bancaire->id)->first();
    expect($bancaireCompte)->not->toBeNull();
    expect($bancaireCompte->numero_pcg)->toBe('5121');
});

test('provisionAll est idempotent — un second appel ne duplique rien', function () {
    $assoId = TenantContext::currentId();
    CompteBancaire::factory()->create(['association_id' => $assoId]);

    $service = app(ComptesProvisioningService::class);
    $service->provisionAll();
    $countApres1 = Compte::count();

    $service->provisionAll();
    $countApres2 = Compte::count();

    expect($countApres2)->toBe($countApres1);
});
