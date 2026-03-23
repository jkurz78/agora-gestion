<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\HelloAssoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    User::factory()->create();
    $this->actingAs(User::first());

    $this->compteHA = CompteBancaire::factory()->create(['nom' => 'HelloAsso', 'solde_initial' => 0]);
    $this->compteCourant = CompteBancaire::factory()->create(['nom' => 'Compte courant']);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $this->compteHA->id,
        'compte_versement_id' => $this->compteCourant->id,
    ]);
});

it('creates virement + locked rapprochement for a complete cashout', function () {
    $tx1 = Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'helloasso_payment_id' => 101,
        'saisi_par' => User::first()->id,
    ]);
    $tx2 = Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 50.00,
        'helloasso_payment_id' => 102,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500,
            'payments' => [['id' => 101], ['id' => 102]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(1);
    $virement = VirementInterne::where('helloasso_cashout_id', 3001)->first();
    expect($virement)->not->toBeNull();
    expect($virement->montant)->toBe('75.00');

    expect($result['rapprochements_created'])->toBe(1);
    $rapprochement = RapprochementBancaire::where('compte_id', $this->compteHA->id)
        ->where('statut', StatutRapprochement::Verrouille)
        ->first();
    expect($rapprochement)->not->toBeNull();
    expect($rapprochement->solde_fin)->toBe($rapprochement->solde_ouverture);

    expect($tx1->fresh()->helloasso_cashout_id)->toBe(3001);
    expect($tx1->fresh()->rapprochement_id)->toBe($rapprochement->id);
    expect($tx2->fresh()->helloasso_cashout_id)->toBe(3001);
    expect($tx2->fresh()->rapprochement_id)->toBe($rapprochement->id);

    expect($virement->fresh()->rapprochement_source_id)->toBe($rapprochement->id);
});

it('skips virement and rapprochement for an incomplete cashout', function () {
    Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'helloasso_payment_id' => 201,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3002,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500,
            'payments' => [['id' => 201], ['id' => 202]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(0);
    expect($result['rapprochements_created'])->toBe(0);
    expect($result['cashouts_incomplets'])->toHaveCount(1);
    expect(VirementInterne::where('helloasso_cashout_id', 3002)->count())->toBe(0);
});

it('updates helloasso_cashout_id on transactions even when incomplete', function () {
    $tx = Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'helloasso_payment_id' => 301,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3003,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500,
            'payments' => [['id' => 301], ['id' => 302]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniserCashouts($cashOuts);

    expect($tx->fresh()->helloasso_cashout_id)->toBe(3003);
});

it('skips already-processed cashouts (idempotent)', function () {
    $rapprochement = RapprochementBancaire::create([
        'compte_id' => $this->compteHA->id,
        'date_fin' => '2025-10-20',
        'solde_ouverture' => 0,
        'solde_fin' => 0,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => User::first()->id,
    ]);

    VirementInterne::factory()->create([
        'helloasso_cashout_id' => 3004,
        'compte_source_id' => $this->compteHA->id,
        'compte_destination_id' => $this->compteCourant->id,
        'montant' => 75.00,
        'rapprochement_source_id' => $rapprochement->id,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3004,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500,
            'payments' => [['id' => 401]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(0);
    expect($result['rapprochements_created'])->toBe(0);
    expect(VirementInterne::where('helloasso_cashout_id', 3004)->count())->toBe(1);
});

it('creates rapprochement for existing virement without one (transitional)', function () {
    $tx = Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 75.00,
        'helloasso_payment_id' => 501,
        'saisi_par' => User::first()->id,
    ]);

    $existingVirement = VirementInterne::factory()->create([
        'helloasso_cashout_id' => 3005,
        'compte_source_id' => $this->compteHA->id,
        'compte_destination_id' => $this->compteCourant->id,
        'montant' => 75.00,
        'rapprochement_source_id' => null,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3005,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500,
            'payments' => [['id' => 501]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    // Virement not re-created, but rapprochement created
    expect($result['virements_created'])->toBe(0);
    expect($result['rapprochements_created'])->toBe(1);
    expect(VirementInterne::where('helloasso_cashout_id', 3005)->count())->toBe(1);

    $rapprochement = RapprochementBancaire::where('compte_id', $this->compteHA->id)
        ->where('statut', StatutRapprochement::Verrouille)
        ->first();
    expect($rapprochement)->not->toBeNull();
    expect($existingVirement->fresh()->rapprochement_source_id)->toBe($rapprochement->id);
    expect($tx->fresh()->rapprochement_id)->toBe($rapprochement->id);
});

it('processes multiple cashouts in chronological order', function () {
    Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 50.00,
        'helloasso_payment_id' => 501,
        'saisi_par' => User::first()->id,
    ]);
    Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'helloasso_payment_id' => 502,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3006,
            'date' => '2025-11-20T10:00:00+02:00',
            'amount' => 2500,
            'payments' => [['id' => 502]],
        ],
        [
            'id' => 3005,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 501]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(2);
    expect($result['rapprochements_created'])->toBe(2);

    $rapprochements = RapprochementBancaire::where('compte_id', $this->compteHA->id)
        ->orderBy('date_fin')
        ->get();
    expect($rapprochements)->toHaveCount(2);
    expect($rapprochements[0]->date_fin->toDateString())->toBe('2025-10-20');
    expect($rapprochements[1]->date_fin->toDateString())->toBe('2025-11-20');
});
