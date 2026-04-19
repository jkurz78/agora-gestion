<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Http\Controllers\TransactionPieceJointeController;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\IncomingDocument;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->aid = $this->association->id;
});

afterEach(function () {
    TenantContext::clear();
});

// ── pieceJointeFullPath() accesseur ───────────────────────────────────────────

it('pieceJointeFullPath() retourne null quand piece_jointe_path est null', function () {
    $tx = Transaction::factory()->create(['piece_jointe_path' => null]);
    expect($tx->pieceJointeFullPath())->toBeNull();
});

it('pieceJointeFullPath() retourne le chemin tenant-scoped complet', function () {
    $tx = Transaction::factory()->create(['piece_jointe_path' => 'justificatif.pdf']);

    $expected = "associations/{$this->aid}/transactions/{$tx->id}/justificatif.pdf";
    expect($tx->pieceJointeFullPath())->toBe($expected);
});

// ── storePieceJointe — nouveau chemin ─────────────────────────────────────────

it('storePieceJointe place le fichier sous associations/{aid}/transactions/{tid}/piece-jointe.{ext}', function () {
    $tx = Transaction::factory()->create();
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    app(TransactionService::class)->storePieceJointe($tx, $file);

    $tx->refresh();
    $expectedShort = 'justificatif.pdf';
    $expectedFull = "associations/{$this->aid}/transactions/{$tx->id}/justificatif.pdf";

    expect($tx->piece_jointe_path)->toBe($expectedShort);
    Storage::disk('local')->assertExists($expectedFull);
});

it('piece_jointe_path contient uniquement le nom court (sans slash)', function () {
    $tx = Transaction::factory()->create();
    $file = UploadedFile::fake()->create('facture.jpg', 50, 'image/jpeg');

    app(TransactionService::class)->storePieceJointe($tx, $file);

    $tx->refresh();
    expect($tx->piece_jointe_path)->not->toContain('/');
});

// ── storePieceJointe remplace la PJ existante ─────────────────────────────────

it('storePieceJointe remplace une PJ existante et efface l\'ancien fichier', function () {
    $tx = Transaction::factory()->create();
    $service = app(TransactionService::class);

    $file1 = UploadedFile::fake()->create('ancienne.pdf', 100, 'application/pdf');
    $service->storePieceJointe($tx, $file1);

    $oldPath = "associations/{$this->aid}/transactions/{$tx->id}/justificatif.pdf";
    Storage::disk('local')->assertExists($oldPath);

    $file2 = UploadedFile::fake()->image('nouvelle.jpg', 800, 600);
    $service->storePieceJointe($tx, $file2);

    $tx->refresh();
    Storage::disk('local')->assertMissing($oldPath);
    $newPath = "associations/{$this->aid}/transactions/{$tx->id}/justificatif.jpg";
    Storage::disk('local')->assertExists($newPath);
    expect($tx->piece_jointe_path)->toBe('justificatif.jpg');
});

// ── deletePieceJointe ─────────────────────────────────────────────────────────

it('deletePieceJointe efface le fichier tenant-scoped et met piece_jointe_path à null', function () {
    $tx = Transaction::factory()->create();
    $service = app(TransactionService::class);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    $service->storePieceJointe($tx, $file);

    $fullPath = "associations/{$this->aid}/transactions/{$tx->id}/justificatif.pdf";
    Storage::disk('local')->assertExists($fullPath);

    $service->deletePieceJointe($tx);

    $tx->refresh();
    expect($tx->piece_jointe_path)->toBeNull();
    Storage::disk('local')->assertMissing($fullPath);
});

// ── storePieceJointeFromPath (routage depuis IncomingDocument) ─────────────────

it('storePieceJointeFromPath copie depuis chemin disque vers le nouveau chemin tenant-scoped', function () {
    $tx = Transaction::factory()->create();

    $sourcePath = "associations/{$this->aid}/incoming-documents/source-abc.pdf";
    Storage::disk('local')->put($sourcePath, 'FAKE PDF BYTES');
    $diskPath = Storage::disk('local')->path($sourcePath);

    app(TransactionService::class)->storePieceJointeFromPath(
        $tx,
        $diskPath,
        'facture-edf.pdf',
        'application/pdf',
    );

    $tx->refresh();
    $expectedShort = 'justificatif.pdf';
    $expectedFull = "associations/{$this->aid}/transactions/{$tx->id}/justificatif.pdf";

    expect($tx->piece_jointe_path)->toBe($expectedShort);
    Storage::disk('local')->assertExists($expectedFull);
    expect(Storage::disk('local')->get($expectedFull))->toBe('FAKE PDF BYTES');
});

// ── Download via TransactionPieceJointeController ─────────────────────────────

it('download via TransactionPieceJointeController sert le bon contenu depuis le chemin tenant-scoped', function () {
    $tx = Transaction::factory()->create([
        'piece_jointe_path' => 'justificatif.pdf',
        'piece_jointe_nom' => 'ma-facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $fullPath = "associations/{$this->aid}/transactions/{$tx->id}/justificatif.pdf";
    Storage::disk('local')->put($fullPath, 'PDF CONTENT');

    $response = $this->get(route('transactions.piece-jointe', $tx));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('ma-facture.pdf');
});

it('download retourne 404 si le fichier est absent du disque', function () {
    $tx = Transaction::factory()->create([
        'piece_jointe_path' => 'justificatif.pdf',
        'piece_jointe_nom' => 'ma-facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);
    // Fichier non créé sur le disque fake

    $this->get(route('transactions.piece-jointe', $tx))->assertNotFound();
});

// ── Suppression de transaction efface la PJ ───────────────────────────────────

it('la suppression d\'une transaction efface aussi la pièce jointe tenant-scoped', function () {
    $sc = SousCategorie::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $service = app(TransactionService::class);

    $tx = $service->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-01',
        'libelle' => 'Test suppression',
        'montant_total' => '50.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-DEL',
        'compte_id' => $compte->id,
    ], [['sous_categorie_id' => $sc->id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    $service->storePieceJointe($tx, $file);

    $tx->refresh();
    $fullPath = $tx->pieceJointeFullPath();
    Storage::disk('local')->assertExists($fullPath);

    $service->delete($tx);

    Storage::disk('local')->assertMissing($fullPath);
});
