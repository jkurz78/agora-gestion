<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// Model tests
it('hasPieceJointe retourne false quand pas de pièce jointe', function () {
    $transaction = Transaction::factory()->create();
    expect($transaction->hasPieceJointe())->toBeFalse();
});

it('hasPieceJointe retourne true quand pièce jointe présente', function () {
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'pieces-jointes/1/justificatif.pdf',
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
        'piece_jointe_path' => 'pieces-jointes/1/justificatif.pdf',
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
    $path = 'pieces-jointes/1/justificatif.pdf';
    Storage::disk('local')->put($path, 'fake-pdf-content');

    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => $path,
        'piece_jointe_nom' => 'ma-facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $response = $this->get(route('transactions.piece-jointe', $transaction));
    $response->assertOk();
    // Check content-disposition contains the filename
    $contentDisposition = $response->headers->get('Content-Disposition');
    expect($contentDisposition)->toContain('ma-facture.pdf');
});

it('refuse l\'accès aux utilisateurs non authentifiés', function () {
    auth()->logout();
    $transaction = Transaction::factory()->create([
        'piece_jointe_path' => 'pieces-jointes/1/justificatif.pdf',
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
    expect($transaction->piece_jointe_path)->toBe("pieces-jointes/{$transaction->id}/justificatif.pdf")
        ->and($transaction->piece_jointe_nom)->toBe('facture.pdf')
        ->and($transaction->piece_jointe_mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->exists($transaction->piece_jointe_path))->toBeTrue();
});

it('storePieceJointe remplace le fichier existant', function () {
    Storage::fake('local');
    $transaction = Transaction::factory()->create();

    $file1 = UploadedFile::fake()->create('ancienne.pdf', 100, 'application/pdf');
    app(TransactionService::class)->storePieceJointe($transaction, $file1);

    $file2 = UploadedFile::fake()->image('nouvelle.jpg', 800, 600);
    app(TransactionService::class)->storePieceJointe($transaction, $file2);

    $transaction->refresh();
    expect($transaction->piece_jointe_nom)->toBe('nouvelle.jpg')
        ->and($transaction->piece_jointe_mime)->toBe('image/jpeg')
        ->and(Storage::disk('local')->exists("pieces-jointes/{$transaction->id}/justificatif.pdf"))->toBeFalse()
        ->and(Storage::disk('local')->exists("pieces-jointes/{$transaction->id}/justificatif.jpg"))->toBeTrue();
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
    $service->deletePieceJointe($transaction);

    $transaction->refresh();
    expect($transaction->piece_jointe_path)->toBeNull()
        ->and($transaction->piece_jointe_nom)->toBeNull()
        ->and($transaction->piece_jointe_mime)->toBeNull()
        ->and(Storage::disk('local')->exists("pieces-jointes/{$transaction->id}/justificatif.pdf"))->toBeFalse();
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
    $path = $transaction->fresh()->piece_jointe_path;

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $service->delete($transaction);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});
