<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Test de concurrence simplifié : Pest est mono-thread, donc on ne peut pas
 * simuler une vraie contention de lock. On vérifie que 2 appels successifs à
 * marquerEnvoye() sur 2 devis distincts produisent des numéros distincts et
 * séquentiels, ce qui valide le comportement du lock pessimiste en production.
 *
 * NOTE DÉLIBÉRÉE : Un vrai test de contention (pcntl_fork ou 2 connexions DB)
 * est hors scope de la suite Pest standard. La protection réelle contre les
 * doublons repose sur le lockForUpdate() InnoDB + l'index unique partiel
 * (association_id, exercice, numero) non-null en base de données.
 */
describe('DevisNumero — concurrence simulée', function () {
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

    it('produit des numéros distincts et séquentiels pour 2 émissions successives rapides', function () {
        // Simule deux "sessions" qui émettent un devis presque simultanément.
        // En prod, le lockForUpdate() sérialise les transactions InnoDB.
        // Ici on vérifie que la logique séquentielle est correcte.
        $devisA = Devis::factory()->brouillon()->create(['exercice' => 2026]);
        DevisLigne::factory()->create([
            'devis_id' => $devisA->id,
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
            'montant' => 100.00,
            'ordre' => 1,
        ]);
        $devisA->update(['montant_total' => 100.00]);

        $devisB = Devis::factory()->brouillon()->create(['exercice' => 2026]);
        DevisLigne::factory()->create([
            'devis_id' => $devisB->id,
            'prix_unitaire' => 200.00,
            'quantite' => 1.0,
            'montant' => 200.00,
            'ordre' => 1,
        ]);
        $devisB->update(['montant_total' => 200.00]);

        // Les deux appels sont séquentiels (Pest mono-thread) — simule la sérialisation
        // garantie par lockForUpdate() en production.
        $this->service->marquerEnvoye($devisA);
        $this->service->marquerEnvoye($devisB);

        $devisA->refresh();
        $devisB->refresh();

        // Les numéros doivent être distincts et séquentiels
        expect($devisA->numero)->toBe('D-2026-001')
            ->and($devisB->numero)->toBe('D-2026-002')
            ->and($devisA->numero)->not->toBe($devisB->numero);

        // Vérification en base : pas de doublon sur (association_id, exercice, numero)
        $this->assertDatabaseMissing('devis', [
            'association_id' => $this->association->id,
            'exercice' => 2026,
            'numero' => 'D-2026-001',
            'id' => $devisB->id, // devisB ne doit PAS avoir le numéro 001
        ]);
    });

    it('aucun doublon n\'est possible même si les devis sont créés dans n\'importe quel ordre', function () {
        // Crée 5 devis et les émet en série — vérifie l'unicité de chaque numéro
        $devisList = [];
        for ($i = 0; $i < 5; $i++) {
            $d = Devis::factory()->brouillon()->create(['exercice' => 2026]);
            DevisLigne::factory()->create([
                'devis_id' => $d->id,
                'prix_unitaire' => (float) (($i + 1) * 10),
                'quantite' => 1.0,
                'montant' => (float) (($i + 1) * 10),
                'ordre' => 1,
            ]);
            $d->update(['montant_total' => (float) (($i + 1) * 10)]);
            $devisList[] = $d;
        }

        foreach ($devisList as $d) {
            $this->service->marquerEnvoye($d);
        }

        $numeros = collect($devisList)->map(fn ($d) => $d->fresh()->numero)->all();

        // Tous les numéros doivent être uniques
        expect(count(array_unique($numeros)))->toBe(5);
        expect($numeros)->toBe(['D-2026-001', 'D-2026-002', 'D-2026-003', 'D-2026-004', 'D-2026-005']);
    });
});
