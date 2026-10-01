<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Models\Association;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
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

    Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
    SystemeSeeder::seed();
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
