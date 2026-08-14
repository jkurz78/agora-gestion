<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;

it('cloisonne fiches et dotations par tenant', function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Tenues tenant A',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    expect(Immobilisation::count())->toBe(1)
        ->and(ImmobilisationDotation::count())->toBe(1);

    // Bascule sur un autre tenant : plus rien ne doit être visible.
    TenantContext::boot(Association::factory()->create());

    expect(Immobilisation::count())->toBe(0)
        ->and(ImmobilisationDotation::count())->toBe(0)
        ->and(app(DotationService::class)->apercu(2026))->toHaveCount(0);
});
