<?php

declare(strict_types=1);

use App\Enums\StatutFactureDeposee;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Services\Portail\FacturePartenaireService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
});

it('crée le record avec statut Soumise', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('facture.pdf', 1024, 'application/pdf');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    expect($depot)->toBeInstanceOf(FacturePartenaireDeposee::class);
    expect($depot->statut)->toBe(StatutFactureDeposee::Soumise);
    expect($depot->exists)->toBeTrue();
    expect($depot->id)->not->toBeNull();
});

it('persiste tiers_id, association_id, date_facture et numero_facture', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('facture.pdf', 512, 'application/pdf');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    expect((int) $depot->tiers_id)->toBe((int) $tiers->id);
    expect((int) $depot->association_id)->toBe((int) $tiers->association_id);
    expect($depot->date_facture->format('Y-m-d'))->toBe('2026-03-15');
    expect($depot->numero_facture)->toBe('FACT-2026-001');
});

it('persiste pdf_taille égale à la taille du fichier', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $sizeKb = 2048;
    $pdf = UploadedFile::fake()->create('facture.pdf', $sizeKb, 'application/pdf');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    // UploadedFile::fake()->create stores sizeKb in kilobytes; getSize() returns bytes
    expect($depot->pdf_taille)->toBe($pdf->getSize());
});

it('stocke le PDF au bon chemin sur le disk local', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('facture.pdf', 1024, 'application/pdf');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    Storage::disk('local')->assertExists($depot->pdf_path);
});

it('le path inclut le slug du numéro et l\'année/mois de la date facture', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('facture.pdf', 512, 'application/pdf');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    expect($depot->pdf_path)->toContain('2026/03/');
    expect($depot->pdf_path)->toContain('fact-2026-001');
    expect($depot->pdf_path)->toContain("associations/{$tiers->association_id}/factures-deposees/");
});

it('le nom de fichier généré ne contient pas le nom uploadé original', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    // Use a distinctive filename whose tokens are absent from all other path components
    // (association id, date, numero slug) so the assertion is meaningful: it would
    // fail if the implementation leaked the original filename into the stored path.
    $pdf = UploadedFile::fake()->create('original-uploaded-name.pdf', 512, 'application/pdf');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    expect(Str::contains($depot->pdf_path, 'original-uploaded-name'))->toBeFalse();
});

it('le path respecte le format complet associations/{id}/factures-deposees/{Y}/{m}/{Y-m-d}-{slug}-{rand6}.pdf', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('upload.pdf', 512, 'application/pdf');
    $assocId = $tiers->association_id;

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    // Pattern: associations/{id}/factures-deposees/2026/03/2026-03-15-fact-2026-001-{6chars}.pdf
    $pattern = "#^associations/{$assocId}/factures-deposees/2026/03/2026-03-15-fact-2026-001-[a-z0-9]{6}\.pdf$#";
    expect($depot->pdf_path)->toMatch($pattern);
});

it('émet le log portail.facture-partenaire.deposee avec les bonnes clés', function () {
    Log::spy();

    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('facture.pdf', 512, 'application/pdf');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $key, array $context) use ($depot, $tiers): bool {
            return $key === 'portail.facture-partenaire.deposee'
                && (int) $context['depot_id'] === (int) $depot->id
                && (int) $context['tiers_id'] === (int) $tiers->id
                && $context['numero'] === $depot->numero_facture;
        });
});

it('trimme les espaces du numero_facture', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('facture.pdf', 512, 'application/pdf');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => '  FACT-2026-001  ',
    ], $pdf);

    expect($depot->numero_facture)->toBe('FACT-2026-001');
});

it('accepte date_facture en tant qu\'objet Carbon', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('facture.pdf', 512, 'application/pdf');
    $date = Carbon::parse('2026-03-15');

    $service = new FacturePartenaireService;
    $depot = $service->submit($tiers, [
        'date_facture' => $date,
        'numero_facture' => 'FACT-2026-001',
    ], $pdf);

    expect($depot->date_facture->format('Y-m-d'))->toBe('2026-03-15');
    Storage::disk('local')->assertExists($depot->pdf_path);
});

it('rolls back the record when PDF storage fails', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdf = UploadedFile::fake()->create('facture.pdf', 512, 'application/pdf');

    $failingDisk = Mockery::mock(Filesystem::class);
    $failingDisk->shouldReceive('putFileAs')->andReturn(false);
    Storage::set('local', $failingDisk);

    $service = new FacturePartenaireService;

    expect(fn () => $service->submit($tiers, [
        'date_facture' => '2026-03-15',
        'numero_facture' => 'FACT-2026-001',
    ], $pdf))->toThrow(RuntimeException::class);

    expect(FacturePartenaireDeposee::count())->toBe(0);
});
