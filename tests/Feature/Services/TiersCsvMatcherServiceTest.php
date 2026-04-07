<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\TiersCsvMatcherService;

// ---------------------------------------------------------------------------
// 1. Row with no match in DB → status=new
// ---------------------------------------------------------------------------
it('marque new une ligne sans correspondance en base', function () {
    $rows = [
        [
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => '',
            'email' => 'jean@example.com',
            'telephone' => '',
            'adresse_ligne1' => '',
            'code_postal' => '',
            'ville' => '',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ],
    ];

    $result = app(TiersCsvMatcherService::class)->match($rows);

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('new');
    expect($result[0]['matched_tiers_id'])->toBeNull();
    expect($result[0]['matched_candidates'])->toBe([]);
    expect($result[0]['conflict_fields'])->toBe([]);
    expect($result[0]['decision_log'])->toBe('Création automatique');
});

// ---------------------------------------------------------------------------
// 2. Row matching by nom+prenom (case insensitive), no conflict → enrichment
// ---------------------------------------------------------------------------
it('détecte un enrichissement par nom+prénom sans conflit', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => null,
        'telephone' => null,
        'adresse_ligne1' => null,
        'code_postal' => null,
        'ville' => null,
    ]);

    $rows = [
        [
            'type' => 'particulier',
            'nom' => 'dupont',   // lowercase — case insensitive match
            'prenom' => 'jean',
            'entreprise' => '',
            'email' => 'jean@example.com',
            'telephone' => '0600000001',
            'adresse_ligne1' => '',
            'code_postal' => '',
            'ville' => '',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ],
    ];

    $result = app(TiersCsvMatcherService::class)->match($rows);

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('enrichment');
    expect($result[0]['matched_tiers_id'])->toBe($tiers->id);
    expect($result[0]['conflict_fields'])->toBe([]);
    expect($result[0]['decision_log'])->toContain('email');
    expect($result[0]['decision_log'])->toContain('telephone');
});

// ---------------------------------------------------------------------------
// 3. Row matching by nom+prenom with conflicting email → conflict
// ---------------------------------------------------------------------------
it('détecte un conflit quand l\'email diffère', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'ancien@example.com',
    ]);

    $rows = [
        [
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => '',
            'email' => 'nouveau@example.com',
            'telephone' => '',
            'adresse_ligne1' => '',
            'code_postal' => '',
            'ville' => '',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ],
    ];

    $result = app(TiersCsvMatcherService::class)->match($rows);

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('conflict');
    expect($result[0]['matched_tiers_id'])->toBe($tiers->id);
    expect($result[0]['conflict_fields'])->toContain('email');
    expect($result[0]['decision_log'])->toBe('');
});

// ---------------------------------------------------------------------------
// 4. Row matching by entreprise (case insensitive) → enrichment
// ---------------------------------------------------------------------------
it('détecte un enrichissement par entreprise', function () {
    $tiers = Tiers::factory()->entreprise()->create([
        'entreprise' => 'ACME Corp',
        'email' => null,
        'telephone' => null,
    ]);

    $rows = [
        [
            'type' => 'entreprise',
            'nom' => '',
            'prenom' => '',
            'entreprise' => 'acme corp',  // lowercase — case insensitive match
            'email' => 'contact@acme.com',
            'telephone' => '',
            'adresse_ligne1' => '',
            'code_postal' => '',
            'ville' => '',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ],
    ];

    $result = app(TiersCsvMatcherService::class)->match($rows);

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('enrichment');
    expect($result[0]['matched_tiers_id'])->toBe($tiers->id);
    expect($result[0]['decision_log'])->toContain('email');
});

// ---------------------------------------------------------------------------
// 5. Row with same email as different tiers → new + warning
// ---------------------------------------------------------------------------
it('ajoute un warning quand l\'email appartient à un autre tiers', function () {
    $existingTiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@example.com',
    ]);

    $rows = [
        [
            'type' => 'particulier',
            'nom' => 'Martin',
            'prenom' => 'Marie',
            'entreprise' => '',
            'email' => 'jean@example.com',  // same email as Dupont Jean
            'telephone' => '',
            'adresse_ligne1' => '',
            'code_postal' => '',
            'ville' => '',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ],
    ];

    $result = app(TiersCsvMatcherService::class)->match($rows);

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('new');
    expect($result[0]['warnings'])->toHaveCount(1);
    expect($result[0]['warnings'][0])->toContain('même email');
    expect($result[0]['warnings'][0])->toContain($existingTiers->displayName());
    expect($result[0]['warnings'][0])->toContain("#{$existingTiers->id}");
});

// ---------------------------------------------------------------------------
// 6. Homonymes (2 tiers with same nom+prenom) → conflict with candidates
// ---------------------------------------------------------------------------
it('détecte des homonymes et retourne les candidats', function () {
    $tiers1 = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean1@example.com',
    ]);

    $tiers2 = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean2@example.com',
    ]);

    $rows = [
        [
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => '',
            'email' => 'jean3@example.com',
            'telephone' => '',
            'adresse_ligne1' => '',
            'code_postal' => '',
            'ville' => '',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ],
    ];

    $result = app(TiersCsvMatcherService::class)->match($rows);

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('conflict');
    expect($result[0]['matched_tiers_id'])->toBeNull();
    expect($result[0]['matched_candidates'])->toHaveCount(2);
    expect($result[0]['matched_candidates'])->toContain($tiers1->id);
    expect($result[0]['matched_candidates'])->toContain($tiers2->id);
    expect($result[0]['decision_log'])->toBe('');
});

// ---------------------------------------------------------------------------
// 7. Tiers has empty fields, row fills them → enrichment with listed fields
// ---------------------------------------------------------------------------
it('liste les champs complétés dans le decision_log', function () {
    Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => null,
        'telephone' => null,
        'adresse_ligne1' => null,
        'code_postal' => null,
        'ville' => null,
    ]);

    $rows = [
        [
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => '',
            'email' => 'jean@example.com',
            'telephone' => '0600000001',
            'adresse_ligne1' => '12 rue de la Paix',
            'code_postal' => '75001',
            'ville' => 'Paris',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ],
    ];

    $result = app(TiersCsvMatcherService::class)->match($rows);

    expect($result[0]['status'])->toBe('enrichment');
    expect($result[0]['decision_log'])->toContain('Enrichissement auto');
    expect($result[0]['decision_log'])->toContain('email');
    expect($result[0]['decision_log'])->toContain('telephone');
    expect($result[0]['decision_log'])->toContain('adresse_ligne1');
    expect($result[0]['decision_log'])->toContain('code_postal');
    expect($result[0]['decision_log'])->toContain('ville');
});

// ---------------------------------------------------------------------------
// 8. Row with empty fields → enrichment (keeps existing, nothing to enrich)
// ---------------------------------------------------------------------------
it('retourne enrichment même quand la ligne CSV a des champs vides', function () {
    Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@example.com',
        'telephone' => '0600000001',
    ]);

    $rows = [
        [
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => '',
            'email' => '',
            'telephone' => '',
            'adresse_ligne1' => '',
            'code_postal' => '',
            'ville' => '',
            'pays' => '',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ],
    ];

    $result = app(TiersCsvMatcherService::class)->match($rows);

    expect($result)->toHaveCount(1);
    expect($result[0]['status'])->toBe('identical');
    expect($result[0]['matched_tiers_id'])->not->toBeNull();
    expect($result[0]['conflict_fields'])->toBe([]);
});
