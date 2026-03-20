<?php

declare(strict_types=1);

use App\Models\Cotisation;
use App\Models\Don;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\TransactionUniverselleService;

beforeEach(function () {
    $this->svc    = app(TransactionUniverselleService::class);
    $this->compte = \App\Models\CompteBancaire::factory()->create(['solde_initial' => 0]);
});

it('retourne toutes les entités par défaut', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Don::factory()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-11']);
    Cotisation::factory()->create(['compte_id' => $this->compte->id, 'date_paiement' => '2025-01-12']);
    VirementInterne::factory()->create(['compte_source_id' => $this->compte->id, 'date' => '2025-01-13']);

    $result = $this->svc->paginate(null, null, null, null, null, null, null, null, null, null, null);
    expect($result['paginator']->total())->toBeGreaterThanOrEqual(5);
});

it('filtre par compteId', function () {
    $autreCompte = \App\Models\CompteBancaire::factory()->create();
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Transaction::factory()->asDepense()->create(['compte_id' => $autreCompte->id, 'date' => '2025-01-10']);

    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null);
    foreach ($result['paginator']->items() as $row) {
        expect($row->compte_id)->toBe($this->compte->id);
    }
});

it('filtre sur types uniquement depense', function () {
    Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-10']);
    Don::factory()->create(['compte_id' => $this->compte->id, 'date' => '2025-01-11']);

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
    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null, true, 'date', 'asc');
    expect($result['soldeAvantPage'])->not->toBeNull();
});

it('retourne soldeAvantPage null quand computeSolde=false', function () {
    $result = $this->svc->paginate($this->compte->id, null, null, null, null, null, null, null, null, null, null, false);
    expect($result['soldeAvantPage'])->toBeNull();
});
