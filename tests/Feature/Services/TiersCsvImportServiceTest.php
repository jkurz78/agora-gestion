<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Services\TiersCsvImportReport;
use App\Services\TiersCsvImportService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($user);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// 1. Import rows with status=new → tiers created in DB, counter correct
// ---------------------------------------------------------------------------
it('crée des tiers pour les lignes avec status new', function () {
    $rows = [
        [
            'status' => 'new',
            'line' => 2,
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => null,
            'email' => 'jean@example.com',
            'telephone' => '0600000001',
            'adresse_ligne1' => '1 rue de Paris',
            'code_postal' => '75001',
            'ville' => 'Paris',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => false,
            'decision_log' => 'Création nouveau tiers',
        ],
        [
            'status' => 'new',
            'line' => 3,
            'type' => 'entreprise',
            'nom' => null,
            'prenom' => null,
            'entreprise' => 'ACME Corp',
            'email' => 'acme@example.com',
            'telephone' => null,
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
            'decision_log' => 'Création nouveau tiers',
        ],
    ];

    $report = app(TiersCsvImportService::class)->import($rows, 'test.csv');

    expect($report)->toBeInstanceOf(TiersCsvImportReport::class);
    expect($report->created)->toBe(2);
    expect($report->enriched)->toBe(0);
    expect($report->total())->toBe(2);

    $this->assertDatabaseHas('tiers', ['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean@example.com']);
    $this->assertDatabaseHas('tiers', ['entreprise' => 'ACME Corp', 'type' => 'entreprise']);
});

// ---------------------------------------------------------------------------
// 2. Import rows with status=enrichment → only empty fields filled
// ---------------------------------------------------------------------------
it('enrichit un tiers existant en remplissant seulement les champs vides', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@existing.com',
        'telephone' => null,
        'adresse_ligne1' => null,
        'code_postal' => null,
        'ville' => null,
        'pour_depenses' => false,
        'pour_recettes' => false,
    ]);

    $rows = [
        [
            'status' => 'enrichment',
            'line' => 2,
            'matched_tiers_id' => $tiers->id,
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => null,
            'email' => 'jean@new.com',
            'telephone' => '0612345678',
            'adresse_ligne1' => '10 rue de Lyon',
            'code_postal' => '69001',
            'ville' => 'Lyon',
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => false,
            'decision_log' => 'Enrichissement automatique',
        ],
    ];

    $report = app(TiersCsvImportService::class)->import($rows, 'test.csv');

    expect($report->enriched)->toBe(1);
    expect($report->created)->toBe(0);

    $tiers->refresh();

    // Email should NOT be overwritten (was already set)
    expect($tiers->getRawOriginal('email'))->toBe('jean@existing.com');

    // Empty fields should be filled
    expect($tiers->getRawOriginal('telephone'))->toBe('0612345678');
    expect($tiers->getRawOriginal('adresse_ligne1'))->toBe('10 rue de Lyon');
    expect($tiers->getRawOriginal('code_postal'))->toBe('69001');
    expect($tiers->getRawOriginal('ville'))->toBe('Lyon');
});

// ---------------------------------------------------------------------------
// 3. Import rows with status=conflict_resolved_merge → tiers updated with merge_data
// ---------------------------------------------------------------------------
it('met à jour un tiers avec les données de fusion résolues', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@old.com',
        'telephone' => '0600000000',
    ]);

    $rows = [
        [
            'status' => 'conflict_resolved_merge',
            'line' => 2,
            'matched_tiers_id' => $tiers->id,
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => null,
            'email' => 'jean@new.com',
            'telephone' => '0699999999',
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
            'merge_data' => [
                'email' => 'jean@new.com',
                'telephone' => '0699999999',
            ],
            'decision_log' => 'Fusion manuelle',
        ],
    ];

    $report = app(TiersCsvImportService::class)->import($rows, 'test.csv');

    expect($report->resolvedMerge)->toBe(1);

    $tiers->refresh();
    expect($tiers->getRawOriginal('email'))->toBe('jean@new.com');
    expect($tiers->getRawOriginal('telephone'))->toBe('0699999999');
});

// ---------------------------------------------------------------------------
// 4. Import rows with status=conflict_resolved_new → new tiers created
// ---------------------------------------------------------------------------
it('crée un nouveau tiers pour les conflits résolus par création', function () {
    $existing = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
    ]);

    $rows = [
        [
            'status' => 'conflict_resolved_new',
            'line' => 2,
            'matched_tiers_id' => $existing->id,
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => null,
            'email' => 'jean.autre@example.com',
            'telephone' => null,
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
            'decision_log' => 'Création forcée (homonyme)',
        ],
    ];

    $report = app(TiersCsvImportService::class)->import($rows, 'test.csv');

    expect($report->resolvedNew)->toBe(1);

    // Two tiers with same nom/prenom should exist
    expect(Tiers::where('nom', 'Dupont')->where('prenom', 'Jean')->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// 5. Mixed rows → all counters correct
// ---------------------------------------------------------------------------
it('gère un import mixte avec tous les types de statut', function () {
    $enrichTarget = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Martin',
        'prenom' => 'Marie',
        'email' => null,
        'telephone' => null,
    ]);

    $mergeTarget = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Bernard',
        'prenom' => 'Pierre',
        'email' => 'pierre@old.com',
    ]);

    $rows = [
        [
            'status' => 'new',
            'line' => 2,
            'type' => 'particulier',
            'nom' => 'Nouveau',
            'prenom' => 'Tiers',
            'entreprise' => null,
            'email' => 'nouveau@example.com',
            'telephone' => null,
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
            'decision_log' => 'Création',
        ],
        [
            'status' => 'enrichment',
            'line' => 3,
            'matched_tiers_id' => $enrichTarget->id,
            'type' => 'particulier',
            'nom' => 'Martin',
            'prenom' => 'Marie',
            'entreprise' => null,
            'email' => 'marie@example.com',
            'telephone' => '0611111111',
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => false,
            'pour_recettes' => false,
            'decision_log' => 'Enrichissement',
        ],
        [
            'status' => 'conflict_resolved_merge',
            'line' => 4,
            'matched_tiers_id' => $mergeTarget->id,
            'type' => 'particulier',
            'nom' => 'Bernard',
            'prenom' => 'Pierre',
            'entreprise' => null,
            'email' => 'pierre@new.com',
            'telephone' => null,
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
            'merge_data' => ['email' => 'pierre@new.com'],
            'decision_log' => 'Fusion',
        ],
        [
            'status' => 'conflict_resolved_new',
            'line' => 5,
            'matched_tiers_id' => $mergeTarget->id,
            'type' => 'particulier',
            'nom' => 'Durand',
            'prenom' => 'Sophie',
            'entreprise' => null,
            'email' => 'sophie@example.com',
            'telephone' => null,
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => false,
            'decision_log' => 'Création forcée',
        ],
    ];

    $report = app(TiersCsvImportService::class)->import($rows, 'test.csv');

    expect($report->created)->toBe(1);
    expect($report->enriched)->toBe(1);
    expect($report->resolvedMerge)->toBe(1);
    expect($report->resolvedNew)->toBe(1);
    expect($report->total())->toBe(4);
    expect($report->lines)->toHaveCount(4);
});

// ---------------------------------------------------------------------------
// 6. Atomic: error mid-import rolls back everything
// ---------------------------------------------------------------------------
it('annule tout en cas d\'erreur durant l\'import (atomicité)', function () {
    $countBefore = Tiers::count();

    $rows = [
        [
            'status' => 'new',
            'line' => 2,
            'type' => 'particulier',
            'nom' => 'Valide',
            'prenom' => 'Un',
            'entreprise' => null,
            'email' => 'valide@example.com',
            'telephone' => null,
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
            'decision_log' => 'Création',
        ],
        [
            // This row will cause a failure: enrichment with non-existent tiers ID
            'status' => 'enrichment',
            'line' => 3,
            'matched_tiers_id' => 999999,
            'type' => 'particulier',
            'nom' => 'Inexistant',
            'prenom' => 'Tiers',
            'entreprise' => null,
            'email' => 'nope@example.com',
            'telephone' => null,
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => false,
            'pour_recettes' => false,
            'decision_log' => 'Enrichissement',
        ],
    ];

    try {
        app(TiersCsvImportService::class)->import($rows, 'test.csv');
    } catch (Throwable) {
        // Expected: findOrFail throws ModelNotFoundException
    }

    // Nothing should have been committed
    expect(Tiers::count())->toBe($countBefore);
    $this->assertDatabaseMissing('tiers', ['nom' => 'Valide', 'prenom' => 'Un']);
});

// ---------------------------------------------------------------------------
// 7. Report toText() contains header, summary, and detail lines
// ---------------------------------------------------------------------------
it('génère un rapport texte avec en-tête, résumé et lignes de détail', function () {
    $report = new TiersCsvImportReport(
        created: 2,
        enriched: 1,
        resolvedMerge: 0,
        resolvedNew: 1,
        lines: [
            ['line' => 2, 'entreprise' => null, 'nom' => 'Dupont', 'prenom' => 'Jean', 'decision' => 'Création'],
            ['line' => 3, 'entreprise' => 'ACME Corp', 'nom' => null, 'prenom' => null, 'decision' => 'Création'],
            ['line' => 4, 'entreprise' => null, 'nom' => 'Martin', 'prenom' => 'Marie', 'decision' => 'Enrichissement'],
            ['line' => 5, 'entreprise' => null, 'nom' => 'Durand', 'prenom' => 'Sophie', 'decision' => 'Création forcée'],
        ],
    );

    $text = $report->toText('import.csv');

    expect($text)->toContain('Import tiers du');
    expect($text)->toContain('fichier: import.csv');
    expect($text)->toContain('4 lignes traitées');
    expect($text)->toContain('2 créés');
    expect($text)->toContain('1 enrichis auto');
    expect($text)->toContain('0 résolus par fusion');
    expect($text)->toContain('1 créés manuellement');
    expect($text)->toContain('Dupont');
    expect($text)->toContain('ACME Corp');
    expect($text)->toContain('Enrichissement');
    expect($text)->toContain('Création forcée');
});

// ---------------------------------------------------------------------------
// 8. Boolean OR logic: existing true + import false → stays true
// ---------------------------------------------------------------------------
it('conserve pour_depenses à true quand existant est true et import est false', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => null,
        'telephone' => null,
        'pour_depenses' => true,
        'pour_recettes' => true,
    ]);

    $rows = [
        [
            'status' => 'enrichment',
            'line' => 2,
            'matched_tiers_id' => $tiers->id,
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'entreprise' => null,
            'email' => 'jean@example.com',
            'telephone' => null,
            'adresse_ligne1' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'France',
            'pour_depenses' => false,
            'pour_recettes' => false,
            'decision_log' => 'Enrichissement',
        ],
    ];

    $report = app(TiersCsvImportService::class)->import($rows, 'test.csv');

    expect($report->enriched)->toBe(1);

    $tiers->refresh();
    // Should stay true (OR logic: true || false = true)
    expect($tiers->pour_depenses)->toBeTrue();
    expect($tiers->pour_recettes)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 9. Enrichment only fills empty fields, doesn't overwrite existing values
// ---------------------------------------------------------------------------
it('n\'écrase pas les valeurs existantes lors de l\'enrichissement', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@existing.com',
        'telephone' => '0601020304',
        'adresse_ligne1' => '5 rue existante',
        'code_postal' => '75000',
        'ville' => 'Paris',
        'pays' => 'France',
        'pour_depenses' => true,
        'pour_recettes' => false,
    ]);

    $rows = [
        [
            'status' => 'enrichment',
            'line' => 2,
            'matched_tiers_id' => $tiers->id,
            'type' => 'particulier',
            'nom' => 'DUPONT-MODIFIE',
            'prenom' => 'Jean-Pierre',
            'entreprise' => null,
            'email' => 'jean@new.com',
            'telephone' => '0699999999',
            'adresse_ligne1' => '99 nouvelle rue',
            'code_postal' => '69000',
            'ville' => 'Lyon',
            'pays' => 'Belgique',
            'pour_depenses' => false,
            'pour_recettes' => true,
            'decision_log' => 'Enrichissement',
        ],
    ];

    $report = app(TiersCsvImportService::class)->import($rows, 'test.csv');

    $tiers->refresh();

    // None of the already-set fields should change
    expect($tiers->getRawOriginal('nom'))->toBe('Dupont');
    expect($tiers->getRawOriginal('prenom'))->toBe('Jean');
    expect($tiers->getRawOriginal('email'))->toBe('jean@existing.com');
    expect($tiers->getRawOriginal('telephone'))->toBe('0601020304');
    expect($tiers->getRawOriginal('adresse_ligne1'))->toBe('5 rue existante');
    expect($tiers->getRawOriginal('code_postal'))->toBe('75000');
    expect($tiers->getRawOriginal('ville'))->toBe('Paris');
    expect($tiers->getRawOriginal('pays'))->toBe('France');

    // pour_depenses stays true (OR logic), pour_recettes becomes true (false || true)
    expect($tiers->pour_depenses)->toBeTrue();
    expect($tiers->pour_recettes)->toBeTrue();
});
