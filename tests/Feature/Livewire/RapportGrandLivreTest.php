<?php

declare(strict_types=1);

use App\Livewire\RapportGrandLivre;
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

function creerCreancePourGrandLivreEcran(object $contexte): Tiers
{
    $tiers = Tiers::factory()->create([
        'association_id' => (int) $contexte->association->id,
        'nom' => 'Client Écran Grand Livre',
        'prenom' => null,
    ]);

    app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => $contexte->compte706,
            'montant' => MontantDecimal::depuisCentimes(12000),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-05'),
        libelle: 'Créance affichage grand livre',
    );

    return $tiers;
}

it('affiche le grand livre filtré par comptes avec les tiers et soldes progressifs', function (): void {
    creerCreancePourGrandLivreEcran($this);

    Livewire::test(RapportGrandLivre::class)
        ->set('dateDebut', '2025-09-01')
        ->set('dateFin', '2026-08-31')
        ->set('comptes', '411')
        ->assertOk()
        ->assertSet('dateDebut', '2025-09-01')
        ->assertSet('dateFin', '2026-08-31')
        ->assertSee('Exporter')
        ->assertSee('411')
        ->assertSee('CLIENT ÉCRAN GRAND LIVRE')
        ->assertSee('Créance affichage grand livre')
        ->assertSee('Solde ouverture')
        ->assertSee('Solde final')
        ->assertSee('120,00');
});

it('expose le grand livre dans les routes rapports', function (): void {
    $this->get(route('rapports.grand-livre'))
        ->assertOk()
        ->assertSeeLivewire(RapportGrandLivre::class);
});
