<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\RemiseBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a PDF for a comptabilised remise', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compteCible = CompteBancaire::factory()->create();
    $sc = SousCategorie::factory()->create();
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $sc->id]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2025-10-01',
    ]);
    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);

    $service = app(RemiseBancaireService::class);
    $remise = $service->creer([
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $compteCible->id,
    ]);
    $service->comptabiliser($remise, [$reglement->id]);

    $response = $this->get(route('banques.remises.pdf', $remise));

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'application/pdf');
});

it('streams PDF inline when mode=inline', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compteCible = CompteBancaire::factory()->create();
    $sc = SousCategorie::factory()->create();
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $sc->id]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2025-10-01',
    ]);
    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 25.00,
    ]);

    $service = app(RemiseBancaireService::class);
    $remise = $service->creer([
        'date' => '2025-10-20',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $compteCible->id,
    ]);
    $service->comptabiliser($remise, [$reglement->id]);

    $response = $this->get(route('banques.remises.pdf', ['remise' => $remise->id, 'mode' => 'inline']));

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'application/pdf');
});
