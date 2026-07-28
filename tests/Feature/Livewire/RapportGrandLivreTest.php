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

// ─────────────────────────────────────────────────────────────────────────────
// Colonnes optionnelles : mode de règlement et justificatif. Purement
// présentation — elles ne touchent ni aux soldes ni au périmètre des écritures.
// ─────────────────────────────────────────────────────────────────────────────

it('masque les colonnes optionnelles par défaut et les affiche à la demande', function (): void {
    // Les en-têtes ne sont rendus que si un compte a des écritures.
    creerCreancePourGrandLivreEcran($this);

    $composant = Livewire::test(RapportGrandLivre::class)
        ->set('dateDebut', '2025-09-01')
        ->set('dateFin', '2026-08-31')
        ->set('comptes', '411');

    // Par défaut : ni « Règlement » ni « Justif. », et 9 colonnes.
    $composant
        ->assertSet('afficherModeReglement', false)
        ->assertSet('afficherJustificatif', false)
        ->assertDontSee('Règlement')
        ->assertDontSee('Justif.');

    expect($composant->instance()->nombreColonnes())->toBe(9);

    // Chaque option ajoute sa colonne — les colspans des lignes de synthèse
    // suivent, sinon l'ouverture et le total se décalent.
    $composant->set('afficherModeReglement', true)->assertSee('Règlement');
    expect($composant->instance()->nombreColonnes())->toBe(10);

    $composant->set('afficherJustificatif', true)->assertSee('Justif.');
    expect($composant->instance()->nombreColonnes())->toBe(11);
});
