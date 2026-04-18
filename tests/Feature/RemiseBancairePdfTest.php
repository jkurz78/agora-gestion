<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

it('generates a PDF for a comptabilised remise', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compteCible = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 30.00,
        'reference' => null,
    ]);

    $service = app(RemiseBancaireService::class);
    $remise = $service->creer([
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $compteCible->id,
    ]);
    $service->comptabiliser($remise, [$tx->id]);

    $response = $this->get(route('banques.remises.pdf', $remise));

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'application/pdf');
});

it('streams PDF inline when mode=inline', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compteCible = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'compte_id' => $compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 25.00,
        'reference' => null,
    ]);

    $service = app(RemiseBancaireService::class);
    $remise = $service->creer([
        'date' => '2025-10-20',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $compteCible->id,
    ]);
    $service->comptabiliser($remise, [$tx->id]);

    $response = $this->get(route('banques.remises.pdf', ['remise' => $remise->id, 'mode' => 'inline']));

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'application/pdf');
});
