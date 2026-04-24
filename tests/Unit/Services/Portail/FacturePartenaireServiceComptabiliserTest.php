<?php

declare(strict_types=1);

use App\Enums\StatutFactureDeposee;
use App\Events\Portail\FactureDeposeeComptabilisee;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Portail\FacturePartenaireService;
use App\Tenant\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// Helper: build a Soumise depot with a real PDF on disk
// ---------------------------------------------------------------------------
function makeDepotWithFile(int $associationId, int $tiersId): FacturePartenaireDeposee
{
    $pdfPath = "associations/{$associationId}/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake-content');

    return FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $associationId,
        'tiers_id' => $tiersId,
        'pdf_path' => $pdfPath,
    ]);
}

// ---------------------------------------------------------------------------
// 1. Comptabilisation valide — statut, transaction_id, traitee_at
// ---------------------------------------------------------------------------
it('met le statut du dépôt à Traitee après comptabilisation', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $depot->refresh();
    expect($depot->statut)->toBe(StatutFactureDeposee::Traitee);
});

it('renseigne transaction_id sur le dépôt après comptabilisation', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $depot->refresh();
    expect((int) $depot->transaction_id)->toBe((int) $transaction->id);
});

it('renseigne traitee_at sur le dépôt après comptabilisation', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $depot->refresh();
    expect($depot->traitee_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. PDF déplacé (ancien absent, nouveau présent)
// ---------------------------------------------------------------------------
it('supprime le PDF de son emplacement d\'origine après comptabilisation', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);
    $oldPath = $depot->pdf_path;

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    Storage::disk('local')->assertMissing($oldPath);
});

it('place le PDF dans le répertoire transactions/{id}/ après comptabilisation', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);
    $basename = basename($depot->pdf_path);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $expectedFullPath = $transaction->storagePath('transactions/'.$transaction->id.'/'.$basename);
    Storage::disk('local')->assertExists($expectedFullPath);
});

// ---------------------------------------------------------------------------
// 3. Transaction — piece_jointe_path / nom / mime
// ---------------------------------------------------------------------------
it('renseigne piece_jointe_path sur la transaction avec le basename du fichier', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);
    $basename = basename($depot->pdf_path);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $transaction->refresh();
    expect($transaction->piece_jointe_path)->toBe($basename);
});

it('renseigne piece_jointe_nom sur la transaction avec numero et date de la facture', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $expectedNom = sprintf(
        'Facture %s du %s.pdf',
        $depot->numero_facture,
        $depot->date_facture->format('d-m-Y'),
    );

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $transaction->refresh();
    expect($transaction->piece_jointe_nom)->toBe($expectedNom);
});

it('piece_jointe_nom ne contient ni / ni \\ (Symfony filename guard)', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $transaction->refresh();
    expect($transaction->piece_jointe_nom)
        ->not->toContain('/')
        ->not->toContain('\\');
});

it('renseigne piece_jointe_mime = application/pdf sur la transaction', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $transaction->refresh();
    expect($transaction->piece_jointe_mime)->toBe('application/pdf');
});

it('pieceJointeFullPath() pointe vers le fichier déplacé', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);
    $basename = basename($depot->pdf_path);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    $transaction->refresh();
    $expectedFullPath = $transaction->storagePath('transactions/'.$transaction->id.'/'.$basename);
    expect($transaction->pieceJointeFullPath())->toBe($expectedFullPath);
});

// ---------------------------------------------------------------------------
// 4. Event dispatché
// ---------------------------------------------------------------------------
it('dispatche l\'event FactureDeposeeComptabilisee', function () {
    Event::fake([FactureDeposeeComptabilisee::class]);

    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    Event::assertDispatched(FactureDeposeeComptabilisee::class, function ($event) use ($depot) {
        return (int) $event->depot->id === (int) $depot->id;
    });
});

// ---------------------------------------------------------------------------
// 5. Guard cross-tenant
// ---------------------------------------------------------------------------
it('lève DomainException si association_id du dépôt différent de celui de la transaction', function () {
    // Association 1 is already booted by the global beforeEach
    $assoc1 = Association::where('id', TenantContext::currentId())->firstOrFail();

    // Create a second association (not booted as current tenant)
    $assoc2 = Association::factory()->create();

    // Tiers for assoc1 (under current tenant)
    $tiers1 = Tiers::factory()->pourDepenses()->create(['association_id' => $assoc1->id]);

    // Tiers for assoc2 (bypass tenant scope with explicit association_id)
    $tiers2 = Tiers::factory()->pourDepenses()->create(['association_id' => $assoc2->id]);

    $pdfPath = "associations/{$assoc1->id}/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'fake-pdf');

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $assoc1->id,
        'tiers_id' => $tiers1->id,
        'pdf_path' => $pdfPath,
    ]);

    // Transaction belongs to assoc2 — different tenant
    $transaction = Transaction::factory()->create([
        'association_id' => $assoc2->id,
        'tiers_id' => $tiers2->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    expect(fn () => (new FacturePartenaireService)->comptabiliser($depot, $transaction))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 6. Guard depot déjà Traitee
// ---------------------------------------------------------------------------
it('lève DomainException si le dépôt est déjà au statut Traitee', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = "associations/{$tiers->association_id}/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'fake-pdf');

    $depot = FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'pdf_path' => $pdfPath,
    ]);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    expect(fn () => (new FacturePartenaireService)->comptabiliser($depot, $transaction))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 6b. Guard depot Rejetee
// ---------------------------------------------------------------------------
it('lève DomainException si le dépôt est au statut Rejetee', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    // Override statut to Rejetee without going through service logic
    $depot->statut = StatutFactureDeposee::Rejetee;
    $depot->save();

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    expect(fn () => (new FacturePartenaireService)->comptabiliser($depot, $transaction))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 7. Guard transaction a déjà une pièce jointe
// ---------------------------------------------------------------------------
it('lève DomainException si la transaction a déjà une pièce jointe', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => 'justificatif-existant.pdf',
        'piece_jointe_nom' => 'existant.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    expect(fn () => (new FacturePartenaireService)->comptabiliser($depot, $transaction))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 8. Log émis avec la bonne clé
// ---------------------------------------------------------------------------
it('émet le log portail.facture-partenaire.comptabilisee avec depot_id et transaction_id', function () {
    Log::spy();

    $tiers = Tiers::factory()->pourDepenses()->create();
    $depot = makeDepotWithFile((int) $tiers->association_id, (int) $tiers->id);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    (new FacturePartenaireService)->comptabiliser($depot, $transaction);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $key, array $context) use ($depot, $transaction): bool {
            return $key === 'portail.facture-partenaire.comptabilisee'
                && (int) $context['depot_id'] === (int) $depot->id
                && (int) $context['transaction_id'] === (int) $transaction->id;
        });
});

// ---------------------------------------------------------------------------
// 9. Storage::move échoue → RuntimeException + rollback
// ---------------------------------------------------------------------------
it('lève RuntimeException si Storage::move échoue et laisse le dépôt intact', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = "associations/{$tiers->association_id}/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'fake-pdf');

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'pdf_path' => $pdfPath,
    ]);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    // Mock the local disk to fail on move
    $failingDisk = Mockery::mock(Filesystem::class);
    $failingDisk->shouldReceive('move')->andReturn(false);
    Storage::set('local', $failingDisk);

    expect(fn () => (new FacturePartenaireService)->comptabiliser($depot, $transaction))
        ->toThrow(RuntimeException::class);

    // Depot must remain Soumise (rollback)
    $fresh = FacturePartenaireDeposee::withoutGlobalScopes()->find($depot->id);
    expect($fresh->statut)->toBe(StatutFactureDeposee::Soumise);
    expect($fresh->transaction_id)->toBeNull();
});

it('ne modifie pas la transaction si Storage::move échoue', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();
    $pdfPath = "associations/{$tiers->association_id}/factures-deposees/2026/03/2026-03-15-fact-001-abc123.pdf";
    Storage::disk('local')->put($pdfPath, 'fake-pdf');

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'pdf_path' => $pdfPath,
    ]);

    $transaction = Transaction::factory()->create([
        'association_id' => $tiers->association_id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'piece_jointe_nom' => null,
        'piece_jointe_mime' => null,
    ]);

    $failingDisk = Mockery::mock(Filesystem::class);
    $failingDisk->shouldReceive('move')->andReturn(false);
    Storage::set('local', $failingDisk);

    try {
        (new FacturePartenaireService)->comptabiliser($depot, $transaction);
    } catch (RuntimeException) {
    }

    $transaction->refresh();
    expect($transaction->piece_jointe_path)->toBeNull();
});
