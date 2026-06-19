<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Provision;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;

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

test('compteDeResultatProvisions — mode PD : totaux neutralisés, résultat net = résultat courant', function () {
    Config::set('compta.use_partie_double', true);

    // Une provision existe en base, mais en mode PD elle doit être ignorée par ce bloc
    // (elle vit déjà dans le grand livre via 681/781).
    Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1000.00,
        'libelle' => 'FNP',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $block = app(RapportService::class)->compteDeResultatProvisions(2025, -500.0, -300.0);

    expect($block['totalProvisions'])->toBe(0.0);
    expect($block['totalProvisionsN1'])->toBe(0.0);
    expect($block['totalExtournes'])->toBe(0.0);
    expect($block['totalExtournesN1'])->toBe(0.0);
    expect($block['provisions'])->toBeEmpty();
    expect($block['provisionsN1'])->toBeEmpty();
    expect($block['extournes'])->toBeEmpty();
    expect($block['extournesN1'])->toBeEmpty();
    // Pas de cascade : brut = net = résultat courant transmis.
    expect($block['resultatBrut'])->toBe(-500.0);
    expect($block['resultatNet'])->toBe(-500.0);
    expect($block['resultatBrutN1'])->toBe(-300.0);
    expect($block['resultatNetN1'])->toBe(-300.0);
});

test('compteDeResultatProvisions — mode legacy : provisions lues depuis la table et appliquées en cascade', function () {
    Config::set('compta.use_partie_double', false);

    Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1000.00,
        'libelle' => 'FNP',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $block = app(RapportService::class)->compteDeResultatProvisions(2025, -500.0, 0.0);

    expect($block['totalProvisions'])->toBe(1000.0);
    expect($block['provisions'])->not->toBeEmpty();
    // Cascade legacy : net = courant + extournes + provisions = -500 + 0 + 1000.
    expect($block['resultatNet'])->toBe(500.0);
});
