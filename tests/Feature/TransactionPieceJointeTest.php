<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Association;
use App\Tenant\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->aid = $this->association->id;
});

afterEach(function () {
    TenantContext::clear();
});

// Model tests
it('hasPieceJointe retourne false quand pas de pièce jointe', function () {
    $transaction = Transaction::factory()->create();
    expect($transaction->hasPieceJointe())->toBeFalse();
});

it('hasPieceJointe retourne true quand pièce jointe présente', function () {
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'justificatif.pdf',
        'piece_jointe_nom' => 'facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);
    expect($transaction->hasPieceJointe())->toBeTrue();
});

it('pieceJointeUrl retourne null sans pièce jointe', function () {
    $transaction = Transaction::factory()->create();
    expect($transaction->pieceJointeUrl())->toBeNull();
});

it('pieceJointeUrl retourne une URL quand pièce jointe présente', function () {
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'justificatif.pdf',
        'piece_jointe_nom' => 'facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);
    expect($transaction->pieceJointeUrl())->toContain('/transactions/'.$transaction->id.'/piece-jointe');
});

// Route tests
it('retourne 404 si la transaction n\'a pas de pièce jointe', function () {
    $transaction = Transaction::factory()->create();
    $this->get(route('transactions.piece-jointe', $transaction))
        ->assertNotFound();
});

it('retourne le fichier avec le bon Content-Disposition', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'justificatif.pdf',
        'piece_jointe_nom' => 'ma-facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $fullPath = "associations/{$this->aid}/transactions/{$transaction->id}/justificatif.pdf";
    Storage::disk('local')->put($fullPath, 'fake-pdf-content');

    $response = $this->get(route('transactions.piece-jointe', $transaction));
    $response->assertOk();
    // Check content-disposition contains the filename
    $contentDisposition = $response->headers->get('Content-Disposition');
    expect($contentDisposition)->toContain('ma-facture.pdf');
});

it('refuse l\'accès aux utilisateurs non authentifiés', function () {
    auth()->logout();
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'justificatif.pdf',
        'piece_jointe_nom' => 'facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $this->get(route('transactions.piece-jointe', $transaction))
        ->assertRedirect(route('login'));
});

// Service tests
it('storePieceJointe stocke le fichier et met à jour la transaction', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    app(TransactionService::class)->storePieceJointe($transaction, $file);

    $transaction->refresh();
    $expectedShort = 'justificatif.pdf';
    $expectedFull  = "associations/{$this->aid}/transactions/{$transaction->id}/justificatif.pdf";

    expect($transaction->piece_jointe_path)->toBe($expectedShort)
        ->and($transaction->piece_jointe_nom)->toBe('facture.pdf')
        ->and($transaction->piece_jointe_mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->exists($expectedFull))->toBeTrue();
});

it('storePieceJointe remplace le fichier existant', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();

    $file1 = UploadedFile::fake()->create('ancienne.pdf', 100, 'application/pdf');
    app(TransactionService::class)->storePieceJointe($transaction, $file1);

    $file2 = UploadedFile::fake()->image('nouvelle.jpg', 800, 600);
    app(TransactionService::class)->storePieceJointe($transaction, $file2);

    $transaction->refresh();
    $oldPath = "associations/{$this->aid}/transactions/{$transaction->id}/justificatif.pdf";
    $newPath = "associations/{$this->aid}/transactions/{$transaction->id}/justificatif.jpg";

    expect($transaction->piece_jointe_nom)->toBe('nouvelle.jpg')
        ->and($transaction->piece_jointe_mime)->toBe('image/jpeg')
        ->and(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($newPath))->toBeTrue();
});

it('storePieceJointe rejette un fichier au MIME non autorisé', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();
    $file = UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload');

    app(TransactionService::class)->storePieceJointe($transaction, $file);
})->throws(InvalidArgumentException::class, 'Type de fichier non autorisé');

it('deletePieceJointe supprime le fichier et remet les colonnes à null', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    $service = app(TransactionService::class);
    $service->storePieceJointe($transaction, $file);

    $fullPath = "associations/{$this->aid}/transactions/{$transaction->id}/justificatif.pdf";

    $service->deletePieceJointe($transaction);

    $transaction->refresh();
    expect($transaction->piece_jointe_path)->toBeNull()
        ->and($transaction->piece_jointe_nom)->toBeNull()
        ->and($transaction->piece_jointe_mime)->toBeNull()
        ->and(Storage::disk('local')->exists($fullPath))->toBeFalse();
});

it('la suppression d\'une transaction supprime aussi la pièce jointe du disque', function () {
    Storage::fake('local');
    $sc = SousCategorie::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $service = app(TransactionService::class);

    $transaction = $service->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-01',
        'libelle' => 'Test',
        'montant_total' => '50.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-PJ',
        'compte_id' => $compte->id,
    ], [['sous_categorie_id' => $sc->id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    $service->storePieceJointe($transaction, $file);
    $transaction->refresh();
    $path = $transaction->pieceJointeFullPath();

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $service->delete($transaction);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('storePieceJointeFromPath copie le fichier depuis un chemin et met à jour la transaction', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();

    // Créer un fichier source sur disque (hors du dossier transactions)
    Storage::disk('local')->put('incoming-documents/source-abc.pdf', 'FAKE PDF BYTES');
    $sourcePath = Storage::disk('local')->path('incoming-documents/source-abc.pdf');

    app(TransactionService::class)->storePieceJointeFromPath(
        $transaction,
        $sourcePath,
        'facture-edf.pdf',
        'application/pdf',
    );

    $transaction->refresh();
    $expectedShort = 'justificatif.pdf';
    $expectedFull  = "associations/{$this->aid}/transactions/{$transaction->id}/justificatif.pdf";

    expect($transaction->piece_jointe_path)->toBe($expectedShort)
        ->and($transaction->piece_jointe_nom)->toBe('facture-edf.pdf')
        ->and($transaction->piece_jointe_mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->exists($expectedFull))->toBeTrue()
        ->and(Storage::disk('local')->get($expectedFull))->toBe('FAKE PDF BYTES');
});

it('storePieceJointeFromPath rejette un MIME non autorisé', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();
    Storage::disk('local')->put('incoming-documents/x.exe', 'MZ');

    app(TransactionService::class)->storePieceJointeFromPath(
        $transaction,
        Storage::disk('local')->path('incoming-documents/x.exe'),
        'virus.exe',
        'application/x-msdownload',
    );
})->throws(InvalidArgumentException::class, 'Type de fichier non autorisé');

it('storePieceJointeFromPath remplace la pièce jointe précédente', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();

    // Première pièce jointe via la méthode existante
    $file1 = UploadedFile::fake()->create('ancienne.pdf', 100, 'application/pdf');
    app(TransactionService::class)->storePieceJointe($transaction, $file1);

    // Remplacer par un fichier depuis disque
    Storage::disk('local')->put('incoming-documents/nouveau.pdf', 'NOUVEAU');
    app(TransactionService::class)->storePieceJointeFromPath(
        $transaction,
        Storage::disk('local')->path('incoming-documents/nouveau.pdf'),
        'nouveau.pdf',
        'application/pdf',
    );

    $transaction->refresh();
    $expectedFull = "associations/{$this->aid}/transactions/{$transaction->id}/justificatif.pdf";

    expect($transaction->piece_jointe_nom)->toBe('nouveau.pdf')
        ->and(Storage::disk('local')->get($expectedFull))->toBe('NOUVEAU');
});
