<?php

declare(strict_types=1);

use App\Enums\Civilite;
use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('cast civilite vers l\'enum Civilite', function (): void {
    $tiers = Tiers::factory()->create(['civilite' => 'M.']);
    expect($tiers->fresh()->civilite)->toBe(Civilite::M);
});

it('accepte civilite null', function (): void {
    $tiers = Tiers::factory()->create(['civilite' => null]);
    expect($tiers->fresh()->civilite)->toBeNull();
});

it('expose adresse_polie pour un homme', function (): void {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'civilite' => 'M.',
        'prenom' => 'Jean',
        'nom' => 'Dupont',
    ]);
    expect($tiers->adresse_polie)->toBe('Monsieur DUPONT');
});

it('expose adresse_polie pour une femme', function (): void {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'civilite' => 'Mme',
        'prenom' => 'Anne',
        'nom' => 'Kurz',
    ]);
    // Nom en MAJUSCULES via accesseur existant
    expect($tiers->adresse_polie)->toBe('Madame KURZ');
});

it('expose adresse_polie sans civilité : prénom + nom', function (): void {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'civilite' => null,
        'prenom' => 'Anne',
        'nom' => 'Kurz',
    ]);
    expect($tiers->adresse_polie)->toBe('Anne KURZ');
});

it('expose adresse_polie pour une entreprise : la raison sociale', function (): void {
    $tiers = Tiers::factory()->create([
        'type' => 'entreprise',
        'entreprise' => 'ACME Corp',
        'civilite' => null,
        'prenom' => null,
        'nom' => 'ACME Corp', // par convention tiers entreprise stocke aussi le nom
    ]);
    expect($tiers->adresse_polie)->toBe('ACME Corp');
});

it('expose salutation par civilité', function (): void {
    $homme = Tiers::factory()->create(['civilite' => 'M.']);
    $femme = Tiers::factory()->create(['civilite' => 'Mme']);
    $sans = Tiers::factory()->create(['civilite' => null]);

    expect($homme->salutation)->toBe('Monsieur')
        ->and($femme->salutation)->toBe('Madame')
        ->and($sans->salutation)->toBe('Madame, Monsieur');
});

it('expose civilite_label dérivé', function (): void {
    $homme = Tiers::factory()->create(['civilite' => 'M.']);
    $sans = Tiers::factory()->create(['civilite' => null]);
    expect($homme->civilite_label)->toBe('Monsieur')
        ->and($sans->civilite_label)->toBe('');
});

it('Enum Civilite expose label() et cases', function (): void {
    expect(Civilite::M->value)->toBe('M.')
        ->and(Civilite::Mme->value)->toBe('Mme')
        ->and(Civilite::M->label())->toBe('Monsieur')
        ->and(Civilite::Mme->label())->toBe('Madame');
});
