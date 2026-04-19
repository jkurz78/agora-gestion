<?php

declare(strict_types=1);

use App\Livewire\Provisions\ProvisionIndex;
use App\Models\Association;
use App\Models\Provision;
use App\Models\SousCategorie;
use App\Models\User;
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
    $provision = Provision::factory()->create(['piece_jointe_path' => null]);
    expect($provision->pieceJointeFullPath())->toBeNull();
});

it('pieceJointeFullPath() retourne le chemin tenant-scoped complet', function () {
    $provision = Provision::factory()->create(['piece_jointe_path' => 'piece-jointe.pdf']);

    $expected = "associations/{$this->aid}/provisions/{$provision->id}/piece-jointe.pdf";
    expect($provision->pieceJointeFullPath())->toBe($expected);
});

it('pieceJointeFullPath() retourne uniquement le basename si piece_jointe_path contient un ancien chemin long', function () {
    $provision = Provision::factory()->create(['piece_jointe_path' => 'provisions/abc123/piece-jointe.pdf']);

    // Même si l'ancienne valeur contenait un slash, basename() isole le fichier
    $expected = "associations/{$this->aid}/provisions/{$provision->id}/piece-jointe.pdf";
    expect($provision->pieceJointeFullPath())->toBe($expected);
});

// ── Upload via ProvisionIndex → chemin tenant-scoped ─────────────────────────

it('upload PJ dans ProvisionIndex place le fichier sous associations/{aid}/provisions/{pid}/piece-jointe.{ext}', function () {
    $component = Livewire\Livewire::test(ProvisionIndex::class);

    // On prépare les données du formulaire
    $sc = SousCategorie::factory()->create();
    $file = UploadedFile::fake()->create('contrat.pdf', 100, 'application/pdf');

    $component
        ->set('libelle', 'Provision test')
        ->set('sous_categorie_id', (string) $sc->id)
        ->set('type', 'depense')
        ->set('montant', '500')
        ->set('piece_jointe', $file)
        ->call('save');

    $provision = Provision::latest('id')->first();
    expect($provision)->not->toBeNull();

    // piece_jointe_path doit être le nom court (sans slash)
    expect($provision->piece_jointe_path)->not->toContain('/');
    expect($provision->piece_jointe_nom)->toBe('contrat.pdf');
    expect($provision->piece_jointe_mime)->toBe('application/pdf');

    // Le fichier doit exister sur le disque local au chemin tenant-scoped
    $expectedFull = "associations/{$this->aid}/provisions/{$provision->id}/{$provision->piece_jointe_path}";
    Storage::disk('local')->assertExists($expectedFull);
});

it('piece_jointe_path sans PJ reste null', function () {
    $sc = SousCategorie::factory()->create();

    Livewire\Livewire::test(ProvisionIndex::class)
        ->set('libelle', 'Provision sans PJ')
        ->set('sous_categorie_id', (string) $sc->id)
        ->set('type', 'recette')
        ->set('montant', '200')
        ->call('save');

    $provision = Provision::latest('id')->first();
    expect($provision->piece_jointe_path)->toBeNull();
});

// ── Migration backfill (chemin court déjà = skip) ────────────────────────────

it('backfill laisse intact un piece_jointe_path déjà court (pas de slash)', function () {
    $provision = Provision::factory()->create(['piece_jointe_path' => 'piece-jointe.pdf']);

    // Simuler la logique de backfill : si basename == path, on skip
    $old = $provision->piece_jointe_path;
    $new = basename($old);

    expect($old)->toBe($new); // Pas de transformation nécessaire
    expect($new)->not->toContain('/');
});

it('backfill convertit un chemin long en basename', function () {
    $provision = Provision::factory()->create([
        'piece_jointe_path' => 'provisions/abc-123-uuid/document-important.pdf',
    ]);

    $old = $provision->piece_jointe_path;
    $new = basename($old);

    expect($new)->toBe('document-important.pdf');
    expect($new)->not->toContain('/');
});
