<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Models\Association;
use App\Models\Devis;
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

// ─── marquerAccepte ────────────────────────────────────────────────────────────

describe('marquerAccepte()', function () {
    it('passe le statut à Accepte depuis Valide et trace utilisateur + datetime', function () {
        $devis = Devis::factory()->valide()->create();

        $before = now()->subSecond();
        $this->service->marquerAccepte($devis);
        $after = now()->addSecond();

        $devis->refresh();

        expect($devis->statut)->toBe(StatutDevis::Accepte)
            ->and((int) $devis->accepte_par_user_id)->toBe((int) $this->user->id)
            ->and($devis->accepte_le)->not->toBeNull()
            ->and($devis->accepte_le->between($before, $after))->toBeTrue();
    });

    it('synchronise l\'instance appelante après transition', function () {
        $devis = Devis::factory()->valide()->create();

        $this->service->marquerAccepte($devis);

        // L'instance $devis doit refléter le nouvel état sans refresh() explicite
        expect($devis->statut)->toBe(StatutDevis::Accepte);
    });

    it('refuse si statut est Brouillon', function () {
        $devis = Devis::factory()->brouillon()->create();

        expect(fn () => $this->service->marquerAccepte($devis))
            ->toThrow(RuntimeException::class, 'Seul un devis validé peut être marqué accepté.');
    });

    it('refuse si statut est déjà Accepte', function () {
        $devis = Devis::factory()->accepte()->create();

        expect(fn () => $this->service->marquerAccepte($devis))
            ->toThrow(RuntimeException::class, 'Seul un devis validé peut être marqué accepté.');
    });

    it('refuse si statut est Refuse', function () {
        $devis = Devis::factory()->refuse()->create();

        expect(fn () => $this->service->marquerAccepte($devis))
            ->toThrow(RuntimeException::class, 'Seul un devis validé peut être marqué accepté.');
    });

    it('refuse si statut est Annule', function () {
        $devis = Devis::factory()->annule()->create();

        expect(fn () => $this->service->marquerAccepte($devis))
            ->toThrow(RuntimeException::class, 'Seul un devis validé peut être marqué accepté.');
    });
});

// ─── marquerRefuse ─────────────────────────────────────────────────────────────

describe('marquerRefuse()', function () {
    it('passe le statut à Refuse depuis Valide et trace utilisateur + datetime', function () {
        $devis = Devis::factory()->valide()->create();

        $before = now()->subSecond();
        $this->service->marquerRefuse($devis);
        $after = now()->addSecond();

        $devis->refresh();

        expect($devis->statut)->toBe(StatutDevis::Refuse)
            ->and((int) $devis->refuse_par_user_id)->toBe((int) $this->user->id)
            ->and($devis->refuse_le)->not->toBeNull()
            ->and($devis->refuse_le->between($before, $after))->toBeTrue();
    });

    it('synchronise l\'instance appelante après transition', function () {
        $devis = Devis::factory()->valide()->create();

        $this->service->marquerRefuse($devis);

        expect($devis->statut)->toBe(StatutDevis::Refuse);
    });

    it('refuse si statut est Brouillon', function () {
        $devis = Devis::factory()->brouillon()->create();

        expect(fn () => $this->service->marquerRefuse($devis))
            ->toThrow(RuntimeException::class, 'Seul un devis validé peut être marqué refusé.');
    });

    it('refuse si statut est Accepte', function () {
        $devis = Devis::factory()->accepte()->create();

        expect(fn () => $this->service->marquerRefuse($devis))
            ->toThrow(RuntimeException::class, 'Seul un devis validé peut être marqué refusé.');
    });

    it('refuse si statut est déjà Refuse', function () {
        $devis = Devis::factory()->refuse()->create();

        expect(fn () => $this->service->marquerRefuse($devis))
            ->toThrow(RuntimeException::class, 'Seul un devis validé peut être marqué refusé.');
    });

    it('refuse si statut est Annule', function () {
        $devis = Devis::factory()->annule()->create();

        expect(fn () => $this->service->marquerRefuse($devis))
            ->toThrow(RuntimeException::class, 'Seul un devis validé peut être marqué refusé.');
    });
});

// ─── annuler ───────────────────────────────────────────────────────────────────

describe('annuler()', function () {
    it('passe le statut à Annule depuis Brouillon et trace utilisateur + datetime', function () {
        $devis = Devis::factory()->brouillon()->create();

        $before = now()->subSecond();
        $this->service->annuler($devis);
        $after = now()->addSecond();

        $devis->refresh();

        expect($devis->statut)->toBe(StatutDevis::Annule)
            ->and((int) $devis->annule_par_user_id)->toBe((int) $this->user->id)
            ->and($devis->annule_le)->not->toBeNull()
            ->and($devis->annule_le->between($before, $after))->toBeTrue();
    });

    it('passe le statut à Annule depuis Valide et trace', function () {
        $devis = Devis::factory()->valide()->create();

        $this->service->annuler($devis);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Annule)
            ->and((int) $devis->annule_par_user_id)->toBe((int) $this->user->id)
            ->and($devis->annule_le)->not->toBeNull();
    });

    it('passe le statut à Annule depuis Accepte et trace', function () {
        $devis = Devis::factory()->accepte()->create();

        $this->service->annuler($devis);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Annule)
            ->and((int) $devis->annule_par_user_id)->toBe((int) $this->user->id);
    });

    it('passe le statut à Annule depuis Refuse et trace', function () {
        $devis = Devis::factory()->refuse()->create();

        $this->service->annuler($devis);

        $devis->refresh();
        expect($devis->statut)->toBe(StatutDevis::Annule)
            ->and((int) $devis->annule_par_user_id)->toBe((int) $this->user->id);
    });

    it('synchronise l\'instance appelante après annulation', function () {
        $devis = Devis::factory()->brouillon()->create();

        $this->service->annuler($devis);

        expect($devis->statut)->toBe(StatutDevis::Annule);
    });

    it('refuse si statut est déjà Annule', function () {
        $devis = Devis::factory()->annule()->create();

        expect(fn () => $this->service->annuler($devis))
            ->toThrow(RuntimeException::class, 'Le devis est déjà annulé.');
    });
});

// ─── Verrouillage post-transition ─────────────────────────────────────────────

describe('verrouillage après transitions terminales', function () {
    it('interdit ajouterLigne après marquerAccepte', function () {
        $devis = Devis::factory()->valide()->create();
        $this->service->marquerAccepte($devis);

        expect(fn () => $this->service->ajouterLigne($devis, [
            'libelle' => 'Tentative',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]))->toThrow(RuntimeException::class);
    });

    it('interdit ajouterLigne après marquerRefuse', function () {
        $devis = Devis::factory()->valide()->create();
        $this->service->marquerRefuse($devis);

        expect(fn () => $this->service->ajouterLigne($devis, [
            'libelle' => 'Tentative',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]))->toThrow(RuntimeException::class);
    });

    it('interdit ajouterLigne après annuler', function () {
        $devis = Devis::factory()->brouillon()->create();
        $this->service->annuler($devis);

        expect(fn () => $this->service->ajouterLigne($devis, [
            'libelle' => 'Tentative',
            'prix_unitaire' => 100.00,
            'quantite' => 1.0,
        ]))->toThrow(RuntimeException::class);
    });
});
