<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Schema;

it('drops est_systeme column', function () {
    expect(Schema::hasColumn('comptes_bancaires', 'est_systeme'))->toBeFalse();
});

it('adds saisie_automatisee column with default false', function () {
    expect(Schema::hasColumn('comptes_bancaires', 'saisie_automatisee'))->toBeTrue();

    $association = Association::factory()->create();
    TenantContext::boot($association);

    $compte = CompteBancaire::factory()->create();
    expect($compte->saisie_automatisee)->toBeFalse();
});

it('deletes the two legacy system accounts', function () {
    $legacyNames = ['Créances à recevoir', 'Remises en banque'];
    $count = CompteBancaire::withoutGlobalScopes()
        ->whereIn('nom', $legacyNames)
        ->count();
    expect($count)->toBe(0);
});

it('marks the HelloAsso account as saisie_automatisee=true after seed', function () {
    $association = Association::factory()->create();
    TenantContext::boot($association);

    $compte = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    HelloAssoParametres::create([
        'association_id' => $association->id,
        'compte_helloasso_id' => $compte->id,
    ]);

    \DB::table('comptes_bancaires')
        ->where('id', $compte->id)
        ->update(['saisie_automatisee' => true]);

    expect($compte->fresh()->saisie_automatisee)->toBeTrue();
});
