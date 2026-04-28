<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Models\Association;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->service = app(FactureService::class);
});

afterEach(function () {
    TenantContext::clear();
});

describe('creerLibreVierge()', function () {
    it('crée une facture brouillon vierge avec les bons attributs', function () {
        $facture = $this->service->creerLibreVierge($this->tiers->id);

        expect($facture)->toBeInstanceOf(Facture::class)
            ->and($facture->exists)->toBeTrue()
            ->and($facture->statut)->toBe(StatutFacture::Brouillon)
            ->and($facture->numero)->toBeNull()
            ->and((int) $facture->tiers_id)->toBe((int) $this->tiers->id)
            ->and($facture->devis_id)->toBeNull()
            ->and((int) $facture->association_id)->toBe((int) $this->association->id)
            ->and((float) $facture->montant_total)->toBe(0.0)
            ->and($facture->mode_paiement_prevu)->toBeNull()
            ->and($facture->date->toDateString())->toBe(now()->toDateString());
    });

    it('ne crée aucune ligne', function () {
        $facture = $this->service->creerLibreVierge($this->tiers->id);

        expect($facture->lignes()->count())->toBe(0);
    });

    it('persiste la facture en base de données', function () {
        $facture = $this->service->creerLibreVierge($this->tiers->id);

        $this->assertDatabaseHas('factures', [
            'id' => $facture->id,
            'statut' => StatutFacture::Brouillon->value,
            'tiers_id' => $this->tiers->id,
            'devis_id' => null,
            'montant_total' => '0.00',
            'numero' => null,
        ]);
    });

    it('refuse un tiers appartenant à une autre association (guard multi-tenant)', function () {
        // Crée un tiers dans une autre association, sans changer le contexte courant
        $autreAssociation = Association::factory()->create();

        $tiersCrosstenant = Tiers::withoutGlobalScopes()->create([
            'association_id' => $autreAssociation->id,
            'type' => 'particulier',
            'nom' => 'Cross',
            'prenom' => 'Tenant',
            'pour_depenses' => false,
            'pour_recettes' => true,
            'est_helloasso' => false,
            'email_optout' => false,
        ]);

        expect(fn () => $this->service->creerLibreVierge($tiersCrosstenant->id))
            ->toThrow(RuntimeException::class);
    });

    it('échoue si TenantContext non booté (fail-closed)', function () {
        TenantContext::clear();

        // TenantModel::creating() ne peut pas injecter l'association_id
        // et TenantScope retourne WHERE 1=0, donc la tentative de créer un Tiers
        // ou toute query sur Tiers::find() retournera null / vide.
        // L'appel doit lever une exception (RuntimeException via requireCurrent ou équivalent).
        expect(fn () => $this->service->creerLibreVierge($this->tiers->id))
            ->toThrow(RuntimeException::class);
    });

    it('crée deux factures distinctes sur deux appels successifs (idempotence)', function () {
        $facture1 = $this->service->creerLibreVierge($this->tiers->id);
        $facture2 = $this->service->creerLibreVierge($this->tiers->id);

        expect($facture1->id)->not->toBe($facture2->id)
            ->and(Facture::count())->toBe(2);
    });
});
