<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Seance;
use App\Models\User;
use App\Services\Emargement\Contracts\QrCodeExtractor;

it('generates an emargement PDF that the QR extractor can read back', function () {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2026-04-08',
    ]);

    $response = $this->actingAs($user)
        ->get(route('gestion.operations.seances.emargement-pdf', [$operation, $seance]));

    $response->assertOk();

    // DomPDF returns a StreamedResponse. Extract the PDF bytes.
    ob_start();
    $response->send();
    $pdfBinary = ob_get_clean();

    // Fallback if ob_start didn't capture (depends on test kernel)
    if ($pdfBinary === '' || $pdfBinary === false) {
        $pdfBinary = $response->getContent();
    }

    expect($pdfBinary)->not->toBeEmpty();
    expect(substr($pdfBinary, 0, 4))->toBe('%PDF');

    // Write to a temp file and run the real extractor
    $tempPdf = storage_path('app/private/temp/roundtrip-'.uniqid().'.pdf');
    @mkdir(dirname($tempPdf), 0755, true);
    file_put_contents($tempPdf, $pdfBinary);

    try {
        $extractor = app(QrCodeExtractor::class);
        $result = $extractor->extractSeanceIdFromPdf($tempPdf);

        expect($result->reason)->toBe('ok');
        expect($result->seanceId)->toBe($seance->id);
    } finally {
        @unlink($tempPdf);
    }
});
