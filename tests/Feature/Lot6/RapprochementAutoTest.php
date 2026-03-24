<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    User::factory()->create();
    $this->actingAs(User::first());
    $this->compte = CompteBancaire::factory()->create(['nom' => 'HelloAsso', 'solde_initial' => 0]);
});

it('creates a locked rapprochement with pointed transactions and virement', function () {
    $tx1 = Transaction::factory()->create([
        'compte_id' => $this->compte->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'saisi_par' => User::first()->id,
    ]);
    $tx2 = Transaction::factory()->create([
        'compte_id' => $this->compte->id,
        'type' => 'recette',
        'montant_total' => 50.00,
        'saisi_par' => User::first()->id,
    ]);

    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => CompteBancaire::factory()->create()->id,
        'montant' => 75.00,
        'saisi_par' => User::first()->id,
    ]);

    $service = new RapprochementBancaireService;
    $rapprochement = $service->createVerrouilleAuto(
        compte: $this->compte,
        dateFin: '2025-10-20',
        soldeFin: 0.00,
        transactionIds: [$tx1->id, $tx2->id],
        virementId: $virement->id,
    );

    expect($rapprochement->statut)->toBe(StatutRapprochement::Verrouille);
    expect($rapprochement->verrouille_at)->not->toBeNull();
    expect($rapprochement->solde_ouverture)->toBe('0.00');
    expect($rapprochement->solde_fin)->toBe('0.00');
    expect($rapprochement->date_fin->toDateString())->toBe('2025-10-20');

    expect($tx1->fresh()->rapprochement_id)->toBe($rapprochement->id);
    expect($tx1->fresh()->pointe)->toBeTrue();
    expect($tx2->fresh()->rapprochement_id)->toBe($rapprochement->id);

    expect($virement->fresh()->rapprochement_source_id)->toBe($rapprochement->id);
});

it('uses solde_fin of last locked rapprochement as solde_ouverture', function () {
    RapprochementBancaire::create([
        'compte_id' => $this->compte->id,
        'date_fin' => '2025-09-15',
        'solde_ouverture' => 0.00,
        'solde_fin' => 150.00,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => User::first()->id,
    ]);

    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => CompteBancaire::factory()->create()->id,
        'montant' => 0,
        'saisi_par' => User::first()->id,
    ]);

    $service = new RapprochementBancaireService;
    $rapprochement = $service->createVerrouilleAuto(
        compte: $this->compte,
        dateFin: '2025-10-20',
        soldeFin: 0.00,
        transactionIds: [],
        virementId: $virement->id,
    );

    expect($rapprochement->solde_ouverture)->toBe('150.00');
});

it('works even when a manual rapprochement en cours exists', function () {
    RapprochementBancaire::create([
        'compte_id' => $this->compte->id,
        'date_fin' => '2025-12-31',
        'solde_ouverture' => 0.00,
        'solde_fin' => 100.00,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => User::first()->id,
    ]);

    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => CompteBancaire::factory()->create()->id,
        'montant' => 0,
        'saisi_par' => User::first()->id,
    ]);

    $service = new RapprochementBancaireService;
    $rapprochement = $service->createVerrouilleAuto(
        compte: $this->compte,
        dateFin: '2025-10-20',
        soldeFin: 0.00,
        transactionIds: [],
        virementId: $virement->id,
    );

    expect($rapprochement->isVerrouille())->toBeTrue();
});
