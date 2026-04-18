<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\User;
use App\Services\RapprochementBancaireService;
use App\Tenant\TenantContext;
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
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

// Model tests
it('hasPieceJointe retourne false quand pas de pièce jointe', function () {
    $rapprochement = RapprochementBancaire::factory()->create();
    expect($rapprochement->hasPieceJointe())->toBeFalse();
});

it('hasPieceJointe retourne true quand pièce jointe présente', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'piece_jointe_path' => 'releve.pdf',
        'piece_jointe_nom' => 'extrait-bancaire.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);
    expect($rapprochement->hasPieceJointe())->toBeTrue();
});

it('pieceJointeUrl retourne null sans pièce jointe', function () {
    $rapprochement = RapprochementBancaire::factory()->create();
    expect($rapprochement->pieceJointeUrl())->toBeNull();
});

it('pieceJointeUrl retourne une URL quand pièce jointe présente', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'piece_jointe_path' => 'releve.pdf',
        'piece_jointe_nom' => 'extrait-bancaire.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);
    expect($rapprochement->pieceJointeUrl())->toContain('/rapprochements/'.$rapprochement->id.'/piece-jointe');
});

// Route tests
it('retourne 404 si le rapprochement n\'a pas de pièce jointe', function () {
    $rapprochement = RapprochementBancaire::factory()->create();
    $this->get(route('rapprochements.piece-jointe', $rapprochement))
        ->assertNotFound();
});

it('retourne le fichier avec le bon Content-Disposition', function () {
    Storage::fake('local');
    $rapprochement = RapprochementBancaire::factory()->create([
        'piece_jointe_path' => 'releve.pdf',
        'piece_jointe_nom' => 'mon-extrait.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $fullPath = "associations/{$this->aid}/rapprochements/{$rapprochement->id}/releve.pdf";
    Storage::disk('local')->put($fullPath, 'fake-pdf-content');

    $response = $this->get(route('rapprochements.piece-jointe', $rapprochement));
    $response->assertOk();
    $contentDisposition = $response->headers->get('Content-Disposition');
    expect($contentDisposition)->toContain('mon-extrait.pdf');
});

it('refuse l\'accès aux utilisateurs non authentifiés', function () {
    auth()->logout();
    $rapprochement = RapprochementBancaire::factory()->create([
        'piece_jointe_path' => 'releve.pdf',
        'piece_jointe_nom' => 'extrait-bancaire.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $this->get(route('rapprochements.piece-jointe', $rapprochement))
        ->assertRedirect(route('login'));
});

// Service tests
it('storePieceJointe stocke le fichier et met à jour le rapprochement', function () {
    Storage::fake('local');
    $rapprochement = RapprochementBancaire::factory()->create();
    $file = UploadedFile::fake()->create('extrait.pdf', 100, 'application/pdf');

    app(RapprochementBancaireService::class)->storePieceJointe($rapprochement, $file);

    $rapprochement->refresh();
    $expectedShort = 'releve.pdf';
    $expectedFull = "associations/{$this->aid}/rapprochements/{$rapprochement->id}/releve.pdf";

    expect($rapprochement->piece_jointe_path)->toBe($expectedShort)
        ->and($rapprochement->piece_jointe_nom)->toBe('extrait.pdf')
        ->and($rapprochement->piece_jointe_mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->exists($expectedFull))->toBeTrue();
});

it('storePieceJointe remplace le fichier existant', function () {
    Storage::fake('local');
    $rapprochement = RapprochementBancaire::factory()->create();

    $file1 = UploadedFile::fake()->create('ancien.pdf', 100, 'application/pdf');
    app(RapprochementBancaireService::class)->storePieceJointe($rapprochement, $file1);

    $file2 = UploadedFile::fake()->image('nouveau.jpg', 800, 600);
    app(RapprochementBancaireService::class)->storePieceJointe($rapprochement, $file2);

    $rapprochement->refresh();
    $oldPath = "associations/{$this->aid}/rapprochements/{$rapprochement->id}/releve.pdf";
    $newPath = "associations/{$this->aid}/rapprochements/{$rapprochement->id}/releve.jpg";

    expect($rapprochement->piece_jointe_nom)->toBe('nouveau.jpg')
        ->and($rapprochement->piece_jointe_mime)->toBe('image/jpeg')
        ->and(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($newPath))->toBeTrue();
});

it('storePieceJointe rejette un fichier au MIME non autorisé', function () {
    Storage::fake('local');
    $rapprochement = RapprochementBancaire::factory()->create();
    $file = UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload');

    app(RapprochementBancaireService::class)->storePieceJointe($rapprochement, $file);
})->throws(InvalidArgumentException::class, 'Type de fichier non autorisé');

it('deletePieceJointe supprime le fichier et remet les colonnes à null', function () {
    Storage::fake('local');
    $rapprochement = RapprochementBancaire::factory()->create();
    $file = UploadedFile::fake()->create('extrait.pdf', 100, 'application/pdf');

    $service = app(RapprochementBancaireService::class);
    $service->storePieceJointe($rapprochement, $file);

    $fullPath = "associations/{$this->aid}/rapprochements/{$rapprochement->id}/releve.pdf";

    $service->deletePieceJointe($rapprochement);

    $rapprochement->refresh();
    expect($rapprochement->piece_jointe_path)->toBeNull()
        ->and($rapprochement->piece_jointe_nom)->toBeNull()
        ->and($rapprochement->piece_jointe_mime)->toBeNull()
        ->and(Storage::disk('local')->exists($fullPath))->toBeFalse();
});

it('la suppression d\'un rapprochement supprime aussi la pièce jointe du disque', function () {
    Storage::fake('local');
    $service = app(RapprochementBancaireService::class);

    $rapprochement = $service->create($this->compte, '2025-10-31', 1200.00);

    $file = UploadedFile::fake()->create('extrait.pdf', 100, 'application/pdf');
    $service->storePieceJointe($rapprochement, $file);
    $rapprochement->refresh();
    $path = $rapprochement->pieceJointeFullPath();

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $service->supprimer($rapprochement);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});
