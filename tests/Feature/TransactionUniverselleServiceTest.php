<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\TransactionUniverselleService;

beforeEach(function () {
    $this->svc = app(TransactionUniverselleService::class);
    $this->compte = CompteBancaire::factory()->create(['solde_initial' => 0]);
});

it('retourne toutes les entités par défaut', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Transaction::factory()->asRecette()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-11']);
    VirementInterne::factory()->create(['compte_source_id' => $this->compte->id, 'date' => '2025-01-13']);

    $result = $this->svc->paginate(null, null, null, null, null, null, null, null, null, null, null);
    // depense + recette + virement_sortant + virement_entrant = 4
    expect($result['paginator']->total())->toBe(4);
});

it('filtre par compteId', function () {
    $autreCompte = CompteBancaire::factory()->create();
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Transaction::factory()->asDepense()->create(['compte_id' => $autreCompte->id, 'date' => '2025-01-10']);

    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null);
    expect($result['paginator']->total())->toBe(1);
});

it('filtre sur types uniquement depense', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Transaction::factory()->asRecette()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-11']);

    $result = $this->svc->paginate(null, null, ['depense'], null, null, null, null, null, null, null, null);
    foreach ($result['paginator']->items() as $row) {
        expect($row->source_type)->toBe('depense');
    }
});

it('filtre par dateDebut et dateFin', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-05']);
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-03-15']);

    $result = $this->svc->paginate(null, null, ['depense'], '2025-03-01', '2025-03-31', null, null, null, null, null, null);
    expect($result['paginator']->total())->toBe(1);
    expect($result['paginator']->items()[0]->date)->toBe('2025-03-15');
});

it('retourne soldeAvantPage non-null quand computeSolde=true et compteId fourni', function () {
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-10',
        'montant_total' => 100.0,
        'mode_paiement' => 'virement',
    ]);
    Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-11',
        'montant_total' => 100.0,
        'mode_paiement' => 'virement',
    ]);

    // Page 2 with perPage=1: soldeAvantPage = solde_initial (0) + 100 (first page row)
    $result = $this->svc->paginate(
        $this->compte->id, null, ['recette'], null, null, null, null, null, null, null, null,
        null, true, 'date', 'asc', 1, 2
    );
    expect($result['soldeAvantPage'])->toBe(100.0);
});

it('retourne soldeAvantPage null quand computeSolde=false', function () {
    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null, null, false);
    expect($result['soldeAvantPage'])->toBeNull();
});

it('exclut les virements quand tiersId est fourni', function () {
    $compteSource = CompteBancaire::factory()->create();
    VirementInterne::factory()->create([
        'compte_source_id' => $compteSource->id,
        'compte_destination_id' => $this->compte->id,
        'date' => '2025-06-01',
    ]);
    $tiers = Tiers::factory()->create();

    $result = $this->svc->paginate(null, $tiers->id, ['virement'], null, null, null, null, null, null, null, null);
    expect($result['paginator']->total())->toBe(0);
});

it('filtre par modePaiement', function () {
    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-10',
        'mode_paiement' => 'cheque',
    ]);
    Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-01-11',
        'mode_paiement' => 'virement',
    ]);

    $result = $this->svc->paginate(null, null, ['depense'], null, null, null, null, null, null, 'cheque', null);
    expect($result['paginator']->total())->toBe(1);
});
