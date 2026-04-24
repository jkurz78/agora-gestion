<?php

declare(strict_types=1);

use App\Enums\StatutFactureDeposee;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Services\Portail\FacturePartenaireService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('supprime le record de la BDD', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;
    $service->oublier($depot, $tiers);

    expect(FacturePartenaireDeposee::find($depot->id))->toBeNull();
});

it('supprime le fichier PDF du disk local', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;
    $service->oublier($depot, $tiers);

    Storage::disk('local')->assertMissing($pdfPath);
});

it('laisse le fichier intact si la suppression BDD échoue', function () {
    // Setup: create a real depot + file
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = "associations/{$tiers->association_id}/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'pdf-content');
    $depot = FacturePartenaireDeposee::factory()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
        'statut' => StatutFactureDeposee::Soumise,
    ]);

    // Force DB delete to throw
    DB::shouldReceive('transaction')->andReturnUsing(function ($callback) {
        throw new QueryException('mysql', 'DELETE', [], new Exception('simulated'));
    });

    expect(fn () => (new FacturePartenaireService)->oublier($depot, $tiers))
        ->toThrow(QueryException::class);

    // Both record and file must remain
    expect(FacturePartenaireDeposee::withoutGlobalScopes()->find($depot->id))->not->toBeNull();
    Storage::disk('local')->assertExists($pdfPath);
});

it('refuse si tiers_id ne correspond pas au tiers passé', function () {
    $tiersProprio = Tiers::factory()->pourDepenses()->create();
    $tiersAutre = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'tiers_id' => $tiersProprio->id,
        'association_id' => $tiersProprio->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;

    expect(fn () => $service->oublier($depot, $tiersAutre))
        ->toThrow(DomainException::class);
});

it('laisse le record intact si tiers cross-tiers refusé', function () {
    $tiersProprio = Tiers::factory()->pourDepenses()->create();
    $tiersAutre = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'tiers_id' => $tiersProprio->id,
        'association_id' => $tiersProprio->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;

    try {
        $service->oublier($depot, $tiersAutre);
    } catch (DomainException) {
    }

    expect(FacturePartenaireDeposee::find($depot->id))->not->toBeNull();
    Storage::disk('local')->assertExists($pdfPath);
});

it('refuse si statut est Traitee', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->traitee()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;

    expect(fn () => $service->oublier($depot, $tiers))
        ->toThrow(DomainException::class);
});

it('laisse le record intact si statut Traitee refusé', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->traitee()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;

    try {
        $service->oublier($depot, $tiers);
    } catch (DomainException) {
    }

    expect(FacturePartenaireDeposee::withoutGlobalScopes()->find($depot->id))->not->toBeNull();
    Storage::disk('local')->assertExists($pdfPath);
});

it('refuse si statut est Rejetee', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->rejetee()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;

    expect(fn () => $service->oublier($depot, $tiers))
        ->toThrow(DomainException::class);
});

it('laisse le record intact si statut Rejetee refusé', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->rejetee()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;

    try {
        $service->oublier($depot, $tiers);
    } catch (DomainException) {
    }

    expect(FacturePartenaireDeposee::withoutGlobalScopes()->find($depot->id))->not->toBeNull();
    Storage::disk('local')->assertExists($pdfPath);
});

it('ne lève pas d\'exception si le fichier PDF est déjà absent du disk', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    // No file stored — path just exists in DB
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-absent.pdf';

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
    ]);

    $service = new FacturePartenaireService;

    // Should not throw
    $service->oublier($depot, $tiers);

    // Record still deleted despite missing file
    expect(FacturePartenaireDeposee::find($depot->id))->toBeNull();
});

it('émet le log portail.facture-partenaire.oubliee avec depot_id et tiers_id', function () {
    Log::spy();

    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = 'associations/1/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf';
    Storage::disk('local')->put($pdfPath, 'pdf-content');

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'tiers_id' => $tiers->id,
        'association_id' => $tiers->association_id,
        'pdf_path' => $pdfPath,
    ]);
    $depotId = $depot->id;

    $service = new FacturePartenaireService;
    $service->oublier($depot, $tiers);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $key, array $context) use ($depotId, $tiers): bool {
            return $key === 'portail.facture-partenaire.oubliee'
                && (int) $context['depot_id'] === (int) $depotId
                && (int) $context['tiers_id'] === (int) $tiers->id;
        });
});
