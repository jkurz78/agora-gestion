<?php

declare(strict_types=1);

use App\Services\Rapports\ProjectionMatrix;

it('computes total from all cells', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 50.0);
    $m->set(1, 10, 2, 100, 30.0);
    $m->set(2, 20, 1, 100, 20.0);

    expect($m->total())->toBe(100.0);
});

it('returns zero total for empty matrix', function (): void {
    $m = new ProjectionMatrix;
    expect($m->total())->toBe(0.0);
    expect($m->isEmpty())->toBeTrue();
});

it('aggregates by sous-categorie', function (): void {
    $m = new ProjectionMatrix;
    // SC 1: 2 tiers × 2 séances = 4 cells
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 10, 2, 100, 20.0);
    $m->set(1, 20, 1, 100, 5.0);
    $m->set(1, 20, 2, 100, 15.0);
    // SC 2: 1 cell
    $m->set(2, 30, 1, 100, 40.0);

    $bySc = $m->bySc();
    expect($bySc[1])->toBe(50.0);
    expect($bySc[2])->toBe(40.0);
});

it('aggregates by categorie using scToCat mapping', function (): void {
    $m = new ProjectionMatrix;
    $m->setScCategory(1, 100);
    $m->setScCategory(2, 100);
    $m->setScCategory(3, 200);

    $m->set(1, 10, 1, 50, 10.0);
    $m->set(2, 10, 1, 50, 20.0);
    $m->set(3, 10, 1, 50, 30.0);

    $byCat = $m->byCat();
    expect($byCat[100])->toBe(30.0);
    expect($byCat[200])->toBe(30.0);
});

it('aggregates by sc × seance', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 20, 1, 100, 5.0);
    $m->set(1, 10, 2, 100, 20.0);

    $byScSeance = $m->byScSeance();
    expect($byScSeance[1][1])->toBe(15.0); // tiers 10 + 20 on seance 1
    expect($byScSeance[1][2])->toBe(20.0);
});

it('aggregates by sc × operation', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 10, 1, 200, 5.0);
    $m->set(1, 10, 2, 100, 20.0);

    $byScOp = $m->byScOp();
    expect($byScOp[1][100])->toBe(30.0); // séance 1 + 2
    expect($byScOp[1][200])->toBe(5.0);
});

it('aggregates by cat × operation', function (): void {
    $m = new ProjectionMatrix;
    $m->setScCategory(1, 100);
    $m->setScCategory(2, 100);

    $m->set(1, 10, 1, 50, 10.0);
    $m->set(1, 10, 1, 60, 5.0);
    $m->set(2, 10, 1, 50, 20.0);

    $byCatOp = $m->byCatOp();
    expect($byCatOp[100][50])->toBe(30.0);
    expect($byCatOp[100][60])->toBe(5.0);
});

it('aggregates by operation', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 10, 2, 200, 5.0);
    $m->set(2, 20, 1, 100, 20.0);

    $byOp = $m->byOp();
    expect($byOp[100])->toBe(30.0);
    expect($byOp[200])->toBe(5.0);
});

it('aggregates by sc × seance × operation', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 20, 1, 100, 5.0);
    $m->set(1, 10, 1, 200, 3.0);
    $m->set(1, 10, 2, 100, 20.0);

    $result = $m->byScSeanceOp();
    expect($result[1][1][100])->toBe(15.0); // tiers 10 + 20
    expect($result[1][1][200])->toBe(3.0);
    expect($result[1][2][100])->toBe(20.0);
});

it('aggregates tiers within a sc', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 10, 2, 100, 5.0);
    $m->set(1, 20, 1, 100, 30.0);

    $byTiers = $m->byScTiers(1);
    expect($byTiers[10])->toBe(15.0);
    expect($byTiers[20])->toBe(30.0);
});

it('aggregates tiers × seance within a sc', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 10, 2, 100, 5.0);
    $m->set(1, 10, 1, 200, 3.0);

    $result = $m->byScTiersSeance(1);
    expect($result[10][1])->toBe(13.0); // op 100 + 200
    expect($result[10][2])->toBe(5.0);
});

it('aggregates tiers × operation within a sc', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 10, 2, 100, 5.0);
    $m->set(1, 10, 1, 200, 3.0);
    $m->set(1, 20, 1, 100, 7.0);

    $result = $m->byScTiersOp(1);
    expect($result[10][100])->toBe(15.0); // séance 1 + 2
    expect($result[10][200])->toBe(3.0);
    expect($result[20][100])->toBe(7.0);
});

it('aggregates by seance × operation', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(2, 20, 1, 100, 5.0);
    $m->set(1, 10, 2, 200, 3.0);

    $result = $m->bySeanceOp();
    expect($result[1][100])->toBe(15.0); // SC 1 + 2
    expect($result[2][200])->toBe(3.0);
});

it('returns empty array for unknown sc in tiers aggregations', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);

    expect($m->byScTiers(999))->toBe([]);
    expect($m->byScTiersSeance(999))->toBe([]);
    expect($m->byScTiersOp(999))->toBe([]);
});

it('caches results and invalidates on set', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);

    expect($m->total())->toBe(10.0);

    $m->set(1, 10, 2, 100, 5.0);

    expect($m->total())->toBe(15.0);
});

it('handles seance 0 (hors séance)', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 0, 100, 25.0);
    $m->set(1, 10, 1, 100, 10.0);

    $byScSeance = $m->byScSeance();
    expect($byScSeance[1][0])->toBe(25.0);
    expect($byScSeance[1][1])->toBe(10.0);
    expect($m->total())->toBe(35.0);
});

it('aggregates tiers × seance × operation within a sc', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 10, 1, 100, 10.0);
    $m->set(1, 10, 1, 200, 5.0);
    $m->set(1, 10, 2, 100, 20.0);
    $m->set(1, 20, 1, 100, 7.0);

    $result = $m->byScTiersSeanceOp(1);
    expect($result[10][1][100])->toBe(10.0)
        ->and($result[10][1][200])->toBe(5.0)
        ->and($result[10][2][100])->toBe(20.0)
        ->and($result[20][1][100])->toBe(7.0);

    expect($m->byScTiersSeanceOp(999))->toBe([]);
});

it('handles tiers 0 (sans tiers)', function (): void {
    $m = new ProjectionMatrix;
    $m->set(1, 0, 1, 100, 15.0);
    $m->set(1, 10, 1, 100, 10.0);

    $byTiers = $m->byScTiers(1);
    expect($byTiers[0])->toBe(15.0);
    expect($byTiers[10])->toBe(10.0);
});
