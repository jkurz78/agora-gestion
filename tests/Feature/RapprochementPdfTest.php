<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\RapprochementBancaire;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->compte = CompteBancaire::factory()->create([
        'nom' => 'Compte Test',
    ]);

    $this->rapprochement = RapprochementBancaire::factory()->create([
        'compte_id'       => $this->compte->id,
        'date_fin'        => now()->format('Y-m-d'),
        'solde_ouverture' => 1000.00,
        'solde_fin'       => 1200.00,
        'saisi_par'       => $this->user->id,
        'statut'          => StatutRapprochement::Verrouille,
    ]);
});

it('requires authentication to download PDF', function () {
    $this->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertRedirect(route('login'));
});

it('returns 404 for non-existent rapprochement', function () {
    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', 99999))
        ->assertNotFound();
});

it('returns a PDF for authenticated user', function () {
    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('generates PDF even when association is not configured', function () {
    expect(Association::find(1))->toBeNull();

    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('generates PDF even when logo file is missing', function () {
    $assoc = Association::find(1) ?? new Association();
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Test', 'logo_path' => 'association/logo-inexistant.png'])->save();

    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('passes only pointed transactions to PDF view', function () {
    Depense::factory()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'libelle'          => 'Dépense pointée',
        'montant_total'    => 100.00,
        'date'             => now()->format('Y-m-d'),
    ]);

    Depense::factory()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => null,
        'libelle'          => 'Dépense non pointée',
        'montant_total'    => 50.00,
        'date'             => now()->subDay()->format('Y-m-d'),
    ]);

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(function (string $view, array $data): bool {
            expect($data['transactions'])->toHaveCount(1);
            expect($data['transactions'][0]['label'])->toBe('Dépense pointée');
            return true;
        })
        ->andReturnSelf();

    Pdf::shouldReceive('download')
        ->once()
        ->andReturn(response('', 200));

    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk();
});
