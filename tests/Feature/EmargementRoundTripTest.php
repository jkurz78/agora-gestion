<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\User;
use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Services\Emargement\ImagickQrCodeExtractor;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

function emargementPdfDepuisReponse(TestResponse $response): string
{
    // DomPDF returns a StreamedResponse. Extract the PDF bytes.
    ob_start();
    $response->send();
    $pdfBinary = ob_get_clean();

    // Fallback if ob_start didn't capture (depends on test kernel)
    if ($pdfBinary === '' || $pdfBinary === false) {
        $pdfBinary = $response->getContent();
    }

    return (string) $pdfBinary;
}

it('generates an emargement PDF that the QR extractor can read back', function () {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2026-04-08',
    ]);

    $response = $this->actingAs($user)
        ->get(route('operations.seances.emargement-pdf', [$operation, $seance]));

    $response->assertOk();

    $pdfBinary = emargementPdfDepuisReponse($response);

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
})->skip(fn (): bool => ! extension_loaded('imagick'), 'Imagick requis pour la rasterisation PDF');

// Production has no zbar: Zxing is the only decoder there. Its default pass
// misses these seance ids on the real sheet (measured); zbar reads them all,
// which is why the defect never showed on a dev machine.
it('reads the QR back without zbar, as in production', function (int $seanceId) {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();
    $seance = Seance::unguarded(fn () => Seance::create([
        'id' => $seanceId,
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2026-04-08',
    ]));

    $response = $this->actingAs($user)
        ->get(route('operations.seances.emargement-pdf', [$operation, $seance]));

    $response->assertOk();

    $tempPdf = storage_path('app/private/temp/roundtrip-'.uniqid().'.pdf');
    @mkdir(dirname($tempPdf), 0755, true);
    file_put_contents($tempPdf, emargementPdfDepuisReponse($response));

    try {
        $result = (new ImagickQrCodeExtractor(zbarBin: null))->extractSeanceIdFromPdf($tempPdf);

        expect($result->reason)->toBe('ok');
        expect($result->seanceId)->toBe($seanceId);
    } finally {
        @unlink($tempPdf);
    }
})->with([12, 24, 35, 131])
    ->skip(fn (): bool => ! extension_loaded('imagick'), 'Imagick requis pour la rasterisation PDF');
