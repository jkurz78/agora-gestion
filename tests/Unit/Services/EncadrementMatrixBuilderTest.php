<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\EncadrementMatrixBuilder;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->operation = Operation::factory()->create();
    $this->seance1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);
    $this->seance2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2, 'date' => now()->addDays(7)]);

    $this->categorie = Categorie::factory()->depense()->create();
    $this->sc1 = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id, 'nom' => 'Encadrement']);
    $this->sc2 = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id, 'nom' => 'Frais déplacement']);

    $this->tiers = Tiers::factory()->create(['nom' => 'DURAND', 'prenom' => 'Sophie']);
    $this->compte = CompteBancaire::factory()->create();
});

it('retourne une matrice avec uniquement des prévisions si aucun réalisé', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'sous_categorie_id' => $this->sc1->id,
        'seance_id' => $this->seance1->id,
        'montant_prevu' => 100,
    ]);

    $data = app(EncadrementMatrixBuilder::class)->build($this->operation);

    expect($data['animateurs'])->toHaveKey($this->tiers->id)
        ->and($data['animateurs'][$this->tiers->id]['sousCategories'])->toHaveKey($this->sc1->id)
        ->and($data['animateurs'][$this->tiers->id]['sousCategories'][$this->sc1->id]['prevuParSeance'][$this->seance1->id] ?? 0)->toBe(100.0)
        ->and($data['animateurs'][$this->tiers->id]['sousCategories'][$this->sc1->id]['realiseParSeance'][$this->seance1->id] ?? 0.0)->toBe(0.0)
        ->and($data['animateurs'][$this->tiers->id]['totalPrevu'])->toBe(100.0)
        ->and($data['animateurs'][$this->tiers->id]['totalRealise'])->toBe(0.0);
});

it('ajoute une ligne fantôme quand un réalisé existe sans prévision', function (): void {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte->id,
        'date' => now(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc2->id,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'montant' => 75,
    ]);

    $data = app(EncadrementMatrixBuilder::class)->build($this->operation);

    expect($data['animateurs'][$this->tiers->id]['sousCategories'])->toHaveKey($this->sc2->id)
        ->and($data['animateurs'][$this->tiers->id]['sousCategories'][$this->sc2->id]['previsionIds'][$this->seance1->id] ?? null)->toBeNull()
        ->and($data['animateurs'][$this->tiers->id]['sousCategories'][$this->sc2->id]['realiseParSeance'][$this->seance1->id])->toBe(75.0)
        ->and($data['animateurs'][$this->tiers->id]['sousCategories'][$this->sc2->id]['hasRealise'])->toBeTrue();
});

it('fusionne prévision et réalisé sur la même cellule', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'sous_categorie_id' => $this->sc1->id,
        'seance_id' => $this->seance1->id,
        'montant_prevu' => 100,
    ]);
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte->id,
        'date' => now(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc1->id,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'montant' => 90,
    ]);

    $data = app(EncadrementMatrixBuilder::class)->build($this->operation);
    $cellule = $data['animateurs'][$this->tiers->id]['sousCategories'][$this->sc1->id];

    expect($cellule['prevuParSeance'][$this->seance1->id])->toBe(100.0)
        ->and($cellule['realiseParSeance'][$this->seance1->id])->toBe(90.0)
        ->and($cellule['hasRealise'])->toBeTrue()
        ->and($cellule['totalPrevu'])->toBe(100.0)
        ->and($cellule['totalRealise'])->toBe(90.0);
});

it('calcule les totaux par séance et globaux', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'sous_categorie_id' => $this->sc1->id,
        'seance_id' => $this->seance1->id,
        'montant_prevu' => 100,
    ]);
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiers->id,
        'sous_categorie_id' => $this->sc1->id,
        'seance_id' => $this->seance2->id,
        'montant_prevu' => 120,
    ]);

    $data = app(EncadrementMatrixBuilder::class)->build($this->operation);

    expect($data['grandPrevu'])->toBe(220.0)
        ->and($data['seancePrevuTotaux'][$this->seance1->id])->toBe(100.0)
        ->and($data['seancePrevuTotaux'][$this->seance2->id])->toBe(120.0);
});

it('expose les réalisés hors-séance via orphanRealiseHorsSeance', function (): void {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte->id,
        'date' => now(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc1->id,
        'operation_id' => $this->operation->id,
        'seance' => null,
        'montant' => 60,
    ]);

    $data = app(EncadrementMatrixBuilder::class)->build($this->operation);

    expect($data['orphanRealiseHorsSeance'][$this->tiers->id] ?? 0)->toBe(60.0)
        ->and($data['animateurs'][$this->tiers->id]['totalRealise'])->toBe(60.0);
});

it('calcule grandRealise depuis séances + orphelins', function (): void {
    // Réalisé attribué à la séance 1
    $tx1 = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte->id,
        'date' => now(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx1->id,
        'sous_categorie_id' => $this->sc1->id,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'montant' => 80,
    ]);

    // Réalisé orphelin (sans séance)
    $tx2 = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte->id,
        'date' => now(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx2->id,
        'sous_categorie_id' => $this->sc1->id,
        'operation_id' => $this->operation->id,
        'seance' => null,
        'montant' => 30,
    ]);

    $data = app(EncadrementMatrixBuilder::class)->build($this->operation);

    expect($data['grandRealise'])->toBe(110.0)
        ->and($data['seanceRealiseTotaux'][$this->seance1->id])->toBe(80.0)
        ->and($data['orphanRealiseHorsSeance'][$this->tiers->id])->toBe(30.0);
});

it('route les realises avec numero seance obsolète vers orphanRealiseHorsSeance', function (): void {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compte->id,
        'date' => now(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc1->id,
        'operation_id' => $this->operation->id,
        'seance' => 99, // numero qui n'existe pas
        'montant' => 42,
    ]);

    $data = app(EncadrementMatrixBuilder::class)->build($this->operation);

    expect($data['orphanRealiseHorsSeance'][$this->tiers->id] ?? 0)->toBe(42.0)
        ->and($data['animateurs'][$this->tiers->id]['totalRealise'])->toBe(42.0)
        ->and($data['seanceRealiseTotaux'])->toBeEmpty();
});

it('ignore les prévisions d\'une autre association (fail-closed)', function (): void {
    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    $opAutre = Operation::factory()->create();
    $sAutre = Seance::create(['operation_id' => $opAutre->id, 'numero' => 1, 'date' => now()]);
    $tAutre = Tiers::factory()->create();
    $cAutre = Categorie::factory()->depense()->create();
    $scAutre = SousCategorie::factory()->create(['categorie_id' => $cAutre->id]);

    EncadrementPrevision::create([
        'operation_id' => $opAutre->id,
        'tiers_id' => $tAutre->id,
        'sous_categorie_id' => $scAutre->id,
        'seance_id' => $sAutre->id,
        'montant_prevu' => 9999,
    ]);

    TenantContext::boot($this->association);
    $data = app(EncadrementMatrixBuilder::class)->build($this->operation);

    expect($data['animateurs'])->toBeEmpty();
});
