<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoParametres;
use App\Models\HelloAssoTierMapping;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $compte = CompteBancaire::factory()->create();
    $sc = SousCategorie::factory()->pourCotisations()->create();

    $this->parametres = HelloAssoParametres::create([
        'association_id' => TenantContext::currentId(),
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test-org',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $compte->id,
        'compte_versement_id' => $compte->id,
        'sous_categorie_cotisation_id' => $sc->id,
        'sous_categorie_don_id' => $sc->id,
        'sous_categorie_inscription_id' => $sc->id,
    ]);

    $this->sc = $sc;
    $this->tiers = Tiers::factory()->create([
        'helloasso_nom' => 'DUPONT',
        'helloasso_prenom' => 'Jean',
        'est_helloasso' => true,
    ]);

    $this->formule = FormuleAdhesion::factory()->modeDuree(12)->create([
        'sous_categorie_id' => $this->sc->id,
        'actif' => true,
    ]);
});

function buildOrder(string $formSlug, int $tierId, int $itemId): array
{
    return [
        'id' => 9001,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => $formSlug,
        'formType' => 'Membership',
        'payments' => [['id' => 7777, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        'items' => [
            [
                'id' => $itemId,
                'amount' => 5000, // 50,00 €
                'type' => 'Membership',
                'tierId' => $tierId,
                'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
            ],
        ],
    ];
}

it('persiste helloasso_form_slug sur la transaction et helloasso_tier_id sur la ligne', function (): void {
    HelloAssoTierMapping::factory()->create([
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 555,
        'target_type' => FormuleAdhesion::class,
        'target_id' => $this->formule->id,
    ]);

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrder('cotisation-2025', 555, 1234)], 2025);

    $tx = Transaction::first();
    expect($tx->helloasso_form_slug)->toBe('cotisation-2025');
    expect($tx->lignes->first()->helloasso_tier_id)->toBe(555);
});

it('crée une adhésion avec la formule mappée (mode durée → date_fin = +12 mois)', function (): void {
    HelloAssoTierMapping::factory()->create([
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 555,
        'target_type' => FormuleAdhesion::class,
        'target_id' => $this->formule->id,
    ]);

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrder('cotisation-2025', 555, 1234)], 2025);

    $adhesion = Adhesion::first();
    expect($adhesion->formule_adhesion_id)->toBe($this->formule->id);
    expect($adhesion->date_debut?->toDateString())->toBe('2025-10-15');
    expect($adhesion->date_fin?->toDateString())->toBe('2026-10-15');
});

it('crée une adhésion legacy si tier non mappé', function (): void {
    // pas de mapping créé, formule désactivée pour tester le chemin legacy
    $this->formule->update(['actif' => false]);

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrder('cotisation-2025', 999, 1234)], 2025);

    $adhesion = Adhesion::first();
    expect($adhesion)->not->toBeNull();
    expect($adhesion->formule_adhesion_id)->toBeNull();
    expect($adhesion->exercice)->toBe(2025);
});
