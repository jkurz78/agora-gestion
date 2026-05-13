<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('expose politesse (forme longue)', function (): void {
    $homme = Tiers::factory()->create(['civilite' => 'M.']);
    $femme = Tiers::factory()->create(['civilite' => 'Mme']);
    $sans = Tiers::factory()->create(['civilite' => null]);

    expect($homme->politesse)->toBe('Monsieur')
        ->and($femme->politesse)->toBe('Madame')
        ->and($sans->politesse)->toBe('');
});

it('expose civilite_nom (M. Kurz)', function (): void {
    $homme = Tiers::factory()->create(['civilite' => 'M.', 'prenom' => 'Jean', 'nom' => 'Dupont']);
    $sans = Tiers::factory()->create(['civilite' => null, 'prenom' => 'Anne', 'nom' => 'Kurz']);

    expect($homme->civilite_nom)->toBe('M. DUPONT')
        ->and($sans->civilite_nom)->toBe('KURZ');
});

it('expose politesse_nom (Monsieur Kurz)', function (): void {
    $femme = Tiers::factory()->create(['civilite' => 'Mme', 'prenom' => 'Anne', 'nom' => 'Kurz']);

    expect($femme->politesse_nom)->toBe('Madame KURZ');
});

it('expose civilite_prenom_nom (M. Jürgen Kurz)', function (): void {
    $homme = Tiers::factory()->create(['civilite' => 'M.', 'prenom' => 'Jürgen', 'nom' => 'Kurz']);
    $sans = Tiers::factory()->create(['civilite' => null, 'prenom' => 'Anne', 'nom' => 'Kurz']);

    expect($homme->civilite_prenom_nom)->toBe('M. Jürgen KURZ')
        ->and($sans->civilite_prenom_nom)->toBe('Anne KURZ');
});

it('expose politesse_prenom_nom (Monsieur Jürgen Kurz)', function (): void {
    $homme = Tiers::factory()->create(['civilite' => 'M.', 'prenom' => 'Jürgen', 'nom' => 'Kurz']);

    expect($homme->politesse_prenom_nom)->toBe('Monsieur Jürgen KURZ');
});

it('pour une entreprise : tous les accessors retournent la raison sociale', function (): void {
    $entreprise = Tiers::factory()->create([
        'type' => 'entreprise',
        'entreprise' => 'ACME Corp',
        'nom' => 'ACME Corp',
        'civilite' => null,
    ]);

    expect($entreprise->civilite_nom)->toBe('ACME Corp')
        ->and($entreprise->politesse_nom)->toBe('ACME Corp')
        ->and($entreprise->civilite_prenom_nom)->toBe('ACME Corp')
        ->and($entreprise->politesse_prenom_nom)->toBe('ACME Corp');
});
