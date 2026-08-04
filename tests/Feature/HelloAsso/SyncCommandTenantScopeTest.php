<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;

afterEach(function (): void {
    TenantContext::clear();
});

it('trouve les tenants configurés sans TenantContext préalablement booté (contexte CLI)', function (): void {
    $association = Association::factory()->create(['nom' => 'Asso HelloAsso']);
    TenantContext::boot($association);

    $compte = CompteBancaire::factory()->create();
    HelloAssoParametres::create([
        'association_id' => $association->id,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $compte->id,
    ]);

    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/orders*' => Http::response(['data' => []]),
    ]);

    // La commande tourne en CLI : aucun tenant n'est booté au démarrage. Le scope
    // global fail-closed de TenantModel doit être neutralisé pour l'inventaire des
    // tenants, sinon la commande ne voit jamais aucune configuration HelloAsso.
    TenantContext::clear();

    $this->artisan('helloasso:sync', ['--exercice' => 2025])
        ->expectsOutputToContain('Sync OK')
        ->assertExitCode(0);
});
