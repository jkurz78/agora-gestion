<?php

declare(strict_types=1);

use App\Enums\SensVentilation;
use App\Livewire\RapportBalance;
use App\Models\Tiers;
use App\Services\Compta\EcritureGenerator;
use App\Support\MontantDecimal;
use App\Tenant\TenantContext;
use Livewire\Livewire;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

function creerCreancePourBalance(object $contexte): Tiers
{
    $tiers = Tiers::factory()->create([
        'association_id' => (int) $contexte->association->id,
        'nom' => 'Client Écran Balance',
    ]);

    app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['sens' => SensVentilation::Credit,
            'compte' => $contexte->compte706,
            'montant' => MontantDecimal::depuisCentimes(12000),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-05'),
        libelle: 'Créance affichage balance',
    );

    return $tiers;
}

it('affiche la balance filtrée par comptes avec le tiers des comptes 411', function (): void {
    creerCreancePourBalance($this);

    Livewire::test(RapportBalance::class)
        ->set('dateDebut', '2025-09-01')
        ->set('dateFin', '2026-08-31')
        ->set('comptes', '411')
        // Balance auxiliaire : le détail par tiers est explicite (par défaut,
        // la balance générale présente le compte collectif 411 en une ligne).
        ->set('detailParTiers', true)
        ->assertOk()
        ->assertSet('dateDebut', '2025-09-01')
        ->assertSet('dateFin', '2026-08-31')
        ->assertSee('Exporter')
        ->assertDontSee('Sélection par classes')
        ->assertSee('411')
        ->assertSee('CLIENT ÉCRAN BALANCE')
        ->assertSee('120,00');
});

it('adapte les colonnes affichées selon le format choisi', function (): void {
    creerCreancePourBalance($this);

    Livewire::test(RapportBalance::class)
        ->set('comptes', '411')
        ->set('colonnes', 2)
        ->assertSee('Solde final')
        ->assertDontSee('Ouverture')
        ->assertDontSee('Mouvements')
        ->set('colonnes', 4)
        ->assertSee('Ouverture')
        ->assertSee('Solde final')
        ->assertDontSee('Mouvements')
        ->set('colonnes', 6)
        ->assertSee('Ouverture')
        ->assertSee('Mouvements')
        ->assertSee('Solde final');
});

it('expose la balance dans les routes rapports', function (): void {
    $this->get(route('rapports.balance'))
        ->assertOk()
        ->assertSeeLivewire(RapportBalance::class);
});

it('propage les filtres courants dans les liens d’export', function (): void {
    $component = Livewire::test(RapportBalance::class)
        ->set('dateDebut', '2025-10-01')
        ->set('dateFin', '2025-10-31')
        ->set('comptes', '411,512')
        ->set('colonnes', 4);

    $url = $component->instance()->exportUrl('xlsx');

    expect($url)
        ->toContain('/rapports/export/balance/xlsx')
        ->toContain('du=2025-10-01')
        ->toContain('au=2025-10-31')
        ->toContain('comptes=411%2C512')
        ->toContain('colonnes=4');
});
