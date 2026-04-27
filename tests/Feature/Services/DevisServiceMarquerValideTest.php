<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create([
        'devis_validite_jours' => 30,
    ]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->service = app(DevisService::class);
});

afterEach(function () {
    TenantContext::clear();
});

// ─── Guards : statut ──────────────────────────────────────────────────────────

describe('marquerValide() — guards statut', function () {
    it('refuse si statut est Valide', function () {
        $devis = Devis::factory()->valide()->create();
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        expect(fn () => $this->service->marquerValide($devis))
            ->toThrow(RuntimeException::class);
    });

    it('refuse si statut est Accepte', function () {
        $devis = Devis::factory()->accepte()->create();
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        expect(fn () => $this->service->marquerValide($devis))
            ->toThrow(RuntimeException::class);
    });

    it('refuse si statut est Refuse', function () {
        $devis = Devis::factory()->refuse()->create();
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        expect(fn () => $this->service->marquerValide($devis))
            ->toThrow(RuntimeException::class);
    });

    it('refuse si statut est Annule', function () {
        $devis = Devis::factory()->annule()->create();
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        expect(fn () => $this->service->marquerValide($devis))
            ->toThrow(RuntimeException::class);
    });
});

// ─── Guards : lignes / montant ────────────────────────────────────────────────

describe('marquerValide() — guards lignes', function () {
    it('refuse si le devis n\'a aucune ligne', function () {
        $devis = Devis::factory()->brouillon()->create();

        expect(fn () => $this->service->marquerValide($devis))
            ->toThrow(RuntimeException::class, 'Au moins une ligne avec un montant est requise pour émettre le devis.');
    });

    it('refuse si toutes les lignes ont montant = 0', function () {
        $devis = Devis::factory()->brouillon()->create();
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 0.00,
            'quantite' => 1.0,
            'montant' => 0.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 0.00]);

        expect(fn () => $this->service->marquerValide($devis))
            ->toThrow(RuntimeException::class, 'Au moins une ligne avec un montant est requise pour émettre le devis.');
    });

    it('accepte si au moins une ligne a montant > 0 même si d\'autres sont à 0', function () {
        $devis = Devis::factory()->brouillon()->create();
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 0.00,
            'quantite' => 1.0,
            'montant' => 0.00,
            'ordre' => 1,
        ]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 500.00,
            'quantite' => 1.0,
            'montant' => 500.00,
            'ordre' => 2,
        ]);
        $devis->update(['montant_total' => 500.00]);

        // Should not throw
        $this->service->marquerValide($devis);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Valide);
    });
});

// ─── Happy path : numérotation ────────────────────────────────────────────────

describe('marquerValide() — happy path', function () {
    it('passe le statut à Valide et attribue D-{exercice}-001 pour le premier devis', function () {
        $devis = Devis::factory()->brouillon()->create(['exercice' => 2026]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        $this->service->marquerValide($devis);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Valide)
            ->and($devis->numero)->toBe('D-2026-001');
    });

    it('attribue des numéros séquentiels pour 3 devis successifs', function () {
        $numeros = [];
        for ($i = 0; $i < 3; $i++) {
            $devis = Devis::factory()->brouillon()->create(['exercice' => 2026]);
            DevisLigne::factory()->create([
                'devis_id' => $devis->id,
                'prix_unitaire' => 50.00,
                'quantite' => 1.0,
                'montant' => 50.00,
                'ordre' => 1,
            ]);
            $devis->update(['montant_total' => 50.00]);

            $this->service->marquerValide($devis);
            $devis->refresh();
            $numeros[] = $devis->numero;
        }

        expect($numeros)->toBe(['D-2026-001', 'D-2026-002', 'D-2026-003']);
    });

    it('déborde à 4 chiffres quand la séquence dépasse 999', function () {
        // Seed 99 devis déjà numérotés D-2026-001 … D-2026-099
        for ($i = 1; $i <= 99; $i++) {
            Devis::factory()->create([
                'exercice' => 2026,
                'statut' => StatutDevis::Valide,
                'numero' => sprintf('D-2026-%03d', $i),
            ]);
        }

        $devis = Devis::factory()->brouillon()->create(['exercice' => 2026]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 10.00,
            'quantite' => 1.0,
            'montant' => 10.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 10.00]);

        $this->service->marquerValide($devis);

        $devis->refresh();
        expect($devis->numero)->toBe('D-2026-100');
    });

    it('déborde correctement au-delà de 999 (4 chiffres)', function () {
        // Seed 999 devis already numbered
        for ($i = 1; $i <= 999; $i++) {
            Devis::factory()->create([
                'exercice' => 2025,
                'statut' => StatutDevis::Valide,
                'numero' => sprintf('D-2025-%03d', $i),
            ]);
        }

        $devis = Devis::factory()->brouillon()->create(['exercice' => 2025]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 10.00,
            'quantite' => 1.0,
            'montant' => 10.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 10.00]);

        $this->service->marquerValide($devis);

        $devis->refresh();
        expect($devis->numero)->toBe('D-2025-1000');
    });
});

// ─── Immuabilité du numéro ────────────────────────────────────────────────────

describe('marquerValide() — immuabilité du numéro', function () {
    it('conserve le numéro déjà attribué si le devis est re-passé en brouillon manuellement', function () {
        // Simule un devis qui a déjà reçu un numéro (Step 6 introduit le vrai rebascule).
        // On positionne manuellement statut=Brouillon avec un numéro déjà attribué.
        $devis = Devis::factory()->create([
            'exercice' => 2026,
            'statut' => StatutDevis::Brouillon,
            'numero' => 'D-2026-042', // Numéro déjà attribué lors d'une émission précédente
        ]);
        DevisLigne::factory()->create([
            'devis_id' => $devis->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis->update(['montant_total' => 100.00]);

        $this->service->marquerValide($devis);

        $devis->refresh();
        // Le numéro existant doit être conservé — pas de réattribution
        expect($devis->numero)->toBe('D-2026-042')
            ->and($devis->statut)->toBe(StatutDevis::Valide);
    });
});

// ─── Isolation par exercice ───────────────────────────────────────────────────

describe('marquerValide() — isolation par exercice', function () {
    it('repart à 001 pour chaque exercice indépendamment', function () {
        $devis2026 = Devis::factory()->brouillon()->create(['exercice' => 2026]);
        DevisLigne::factory()->create([
            'devis_id' => $devis2026->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devis2026->update(['montant_total' => 100.00]);
        $this->service->marquerValide($devis2026);
        $devis2026->refresh();

        $devis2025 = Devis::factory()->brouillon()->create(['exercice' => 2025]);
        DevisLigne::factory()->create([
            'devis_id' => $devis2025->id,
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        $devis2025->update(['montant_total' => 200.00]);
        $this->service->marquerValide($devis2025);
        $devis2025->refresh();

        expect($devis2026->numero)->toBe('D-2026-001')
            ->and($devis2025->numero)->toBe('D-2025-001');
    });
});

// ─── Isolation multi-tenant ───────────────────────────────────────────────────

describe('marquerValide() — isolation multi-tenant', function () {
    it('repart à 001 pour chaque association indépendamment sur le même exercice', function () {
        $assoBeta = Association::factory()->create(['devis_validite_jours' => 30]);

        // Émettre un devis pour l'association courante (alpha)
        $devisAlpha = Devis::factory()->brouillon()->create(['exercice' => 2026]);
        DevisLigne::factory()->create([
            'devis_id' => $devisAlpha->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devisAlpha->update(['montant_total' => 100.00]);
        $this->service->marquerValide($devisAlpha);
        $devisAlpha->refresh();

        // Passer sur l'association beta
        TenantContext::boot($assoBeta);

        $userBeta = User::factory()->create();
        $userBeta->associations()->attach($assoBeta->id, ['role' => 'admin', 'joined_at' => now()]);
        $this->actingAs($userBeta);

        $tiers2 = Tiers::factory()->create(); // créé dans le tenant beta
        $devisBeta = Devis::factory()->create([
            'association_id' => $assoBeta->id,
            'tiers_id' => $tiers2->id,
            'exercice' => 2026,
            'statut' => StatutDevis::Brouillon,
            'numero' => null,
            'montant_total' => 0,
        ]);
        DevisLigne::factory()->create([
            'devis_id' => $devisBeta->id,
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        $devisBeta->update(['montant_total' => 200.00]);

        $this->service->marquerValide($devisBeta);
        $devisBeta->refresh();

        expect($devisAlpha->numero)->toBe('D-2026-001')
            ->and($devisBeta->numero)->toBe('D-2026-001'); // Séquence indépendante
    });
});
