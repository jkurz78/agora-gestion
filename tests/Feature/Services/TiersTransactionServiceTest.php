<?php

declare(strict_types=1);

use App\Models\Cotisation;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\TiersTransactionService;

beforeEach(function (): void {
    $this->tiers = Tiers::factory()->create();
    $this->service = new TiersTransactionService();
    session(['exercice_actif' => 2025]);
});

it('retourne les transactions de tous les types pour un tiers', function (): void {
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Ma dépense']);
    Don::factory()->create(['tiers_id' => $this->tiers->id, 'objet' => 'Mon don']);

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(2);
});

it('filtre par type', function (): void {
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id]);
    Don::factory()->create(['tiers_id' => $this->tiers->id]);

    $result = $this->service->paginate($this->tiers, 'don', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->source_type)->toBe('don');
});

it('filtre par texte sur le libellé', function (): void {
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Frais transport']);
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Loyer bureau']);

    $result = $this->service->paginate($this->tiers, '', '', '', 'Loyer', 'date', 'desc');

    expect($result->total())->toBe(1);
});

it('filtre par date début', function (): void {
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'date' => '2025-10-01']);
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'date' => '2025-12-01']);

    $result = $this->service->paginate($this->tiers, '', '2025-11-01', '', '', 'date', 'desc');

    expect($result->total())->toBe(1);
});

it('exclut les transactions soft-deletées', function (): void {
    $dep = Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id]);
    $dep->delete();

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(0);
});

it('ne retourne pas les transactions d\'un autre tiers', function (): void {
    $autre = Tiers::factory()->create();
    Transaction::factory()->asDepense()->create(['tiers_id' => $autre->id]);

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(0);
});

it('trie par montant desc', function (): void {
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'montant_total' => 100]);
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'montant_total' => 50]);

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'montant', 'desc');

    expect((float) $result->items()[0]->montant)->toBeGreaterThan((float) $result->items()[1]->montant);
});

it('inclut les recettes du tiers', function (): void {
    Transaction::factory()->asRecette()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Ma recette']);

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->source_type)->toBe('recette');
});

it('inclut les cotisations du tiers', function (): void {
    Cotisation::factory()->create(['tiers_id' => $this->tiers->id, 'exercice' => 2025]);

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->source_type)->toBe('cotisation');
});

it('filtre par date fin', function (): void {
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'date' => '2025-10-01']);
    Transaction::factory()->asDepense()->create(['tiers_id' => $this->tiers->id, 'date' => '2025-12-01']);

    $result = $this->service->paginate($this->tiers, '', '', '2025-11-01', '', 'date', 'desc');

    expect($result->total())->toBe(1);
});
