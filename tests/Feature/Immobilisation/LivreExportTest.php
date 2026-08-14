<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Livre des immobilisations exporté en PDF et en Excel, par le même
 * RapportExportController que les autres rapports — même route, même gabarit
 * de mise en page, même en-tête d'exercice.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Deux gros 4x4',
        quantite: 2,
        compte: Compte::ofNumero('2154'),
        compteAmortissement: Compte::ofNumero('28154'),
        montant: '30000.00',
        dateAchat: Carbon::parse('2026-10-15'),
        dateMiseEnService: Carbon::parse('2026-10-15'),
        dureeMois: 96,
        modePaiement: null,
        compteTresorerie: null,
    );
});

it('exporte le livre en PDF', function (): void {
    $response = $this->get(route('rapports.export', [
        'rapport' => 'immobilisations',
        'format' => 'pdf',
        'exercice' => 2026,
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('exporte le livre en Excel avec la VNC de fin d’exercice', function (): void {
    $response = $this->get(route('rapports.export', [
        'rapport' => 'immobilisations',
        'format' => 'xlsx',
        'exercice' => 2026,
    ]));

    $response->assertOk();

    $chemin = tempnam(sys_get_temp_dir(), 'livre').'.xlsx';
    file_put_contents($chemin, $response->streamedContent());
    $feuille = IOFactory::load($chemin)->getActiveSheet();

    // En-têtes, puis la ligne de la fiche : 30 000 € brut, 3 437,50 € dotés sur
    // 11 mois, VNC 26 562,50 €.
    expect($feuille->getCell('A1')->getValue())->toBe('N°')
        ->and($feuille->getCell('A2')->getValue())->toBe('IM00001')
        ->and(round((float) $feuille->getCell('H2')->getValue(), 2))->toBe(30000.00)
        ->and(round((float) $feuille->getCell('I2')->getValue(), 2))->toBe(3437.50)
        ->and(round((float) $feuille->getCell('K2')->getValue(), 2))->toBe(26562.50);

    unlink($chemin);
});

it('nomme le fichier avec l’exercice', function (): void {
    $response = $this->get(route('rapports.export', [
        'rapport' => 'immobilisations',
        'format' => 'xlsx',
        'exercice' => 2026,
    ]));

    expect($response->headers->get('content-disposition'))
        ->toContain('Livre des immobilisations')
        ->toContain('2026');
});

/**
 * L'export suit l'exercice actif de l'application — celui du menu Exercices —
 * et n'introduit pas sa propre notion d'exercice. Un sélecteur local sur
 * l'écran du registre a été retiré pour cette raison le 13/08/2026 : deux
 * notions d'exercice concurrentes finissent toujours par diverger.
 */
it('exporte l’exercice actif de l’application, sans paramètre', function (): void {
    session(['exercice_actif' => 2026]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'immobilisations',
        'format' => 'xlsx',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('2026');

    session(['exercice_actif' => 2027]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'immobilisations',
        'format' => 'xlsx',
    ]));

    expect($response->headers->get('content-disposition'))->toContain('2027');

    session()->forget('exercice_actif');
});

it('n’expose plus de sélecteur d’exercice sur le registre', function (): void {
    Livewire\Livewire::test(ImmobilisationIndex::class)
        ->assertDontSeeHtml('wire:model.live="exerciceExport"');
});
