<?php

declare(strict_types=1);

use App\Services\Questionnaire\RessentiScanMeasurer;

it('analyse directement une image sans rasterisation', function (): void {
    $mesures = app(RessentiScanMeasurer::class)->mesurerDocument(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
        'image/png',
    );

    expect($mesures)->toHaveCount(2);
    expect($mesures[0]['pct'])->toEqualWithDelta(17.4, 1.5);
    expect($mesures[1]['pct'])->toEqualWithDelta(21.6, 1.5);
});

it('rasterise un PDF et concatène les mesures de toutes les pages', function (): void {
    $mesures = app(RessentiScanMeasurer::class)->mesurerDocument(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.pdf'),
        'application/pdf',
    );

    expect($mesures)->toHaveCount(2);
    expect($mesures[0]['pct'])->toEqualWithDelta(17.4, 2.0);
    expect($mesures[1]['pct'])->toEqualWithDelta(21.6, 2.0);
})->skip(fn (): bool => ! extension_loaded('imagick'), 'Imagick requis pour la rasterisation PDF');

it('retourne null quand le PDF est illisible', function (): void {
    $chemin = sys_get_temp_dir().'/corrompu-'.uniqid().'.pdf';
    file_put_contents($chemin, 'pas un pdf');

    expect(app(RessentiScanMeasurer::class)->mesurerDocument($chemin, 'application/pdf'))->toBeNull();
    @unlink($chemin);
})->skip(fn (): bool => ! extension_loaded('imagick'), 'Imagick requis pour la rasterisation PDF');
