<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::find(TenantContext::currentId());
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->admin);

    $tiers = Tiers::factory()->create();
    $this->ndf = NoteDeFrais::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
    ]);
    $this->ligne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $this->ndf->id,
    ]);
});

it('sert la PJ NDF avec CSP sandbox, no-sniff et Content-Disposition attachment', function () {
    $path = "associations/{$this->association->id}/ndf/{$this->ndf->id}/ligne-{$this->ligne->id}.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 ndf');
    $this->ligne->update(['piece_jointe_path' => $path]);

    $response = $this->get(route('comptabilite.ndf.piece-jointe', [
        'noteDeFrais' => $this->ndf->id,
        'ligne' => $this->ligne->id,
    ]));

    $response->assertStatus(200);
    expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('retourne 404 quand la ligne appartient à une autre NDF', function () {
    $autreNdf = NoteDeFrais::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->ndf->tiers_id,
    ]);
    $autreLigne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $autreNdf->id,
    ]);

    $this->get(route('comptabilite.ndf.piece-jointe', [
        'noteDeFrais' => $this->ndf->id,
        'ligne' => $autreLigne->id,
    ]))->assertNotFound();
});

it('retourne 404 quand piece_jointe_path est null', function () {
    $this->ligne->update(['piece_jointe_path' => null]);

    $this->get(route('comptabilite.ndf.piece-jointe', [
        'noteDeFrais' => $this->ndf->id,
        'ligne' => $this->ligne->id,
    ]))->assertNotFound();
});

it('retourne 404 quand le fichier n\'existe pas sur le disque', function () {
    $this->ligne->update(['piece_jointe_path' => 'associations/1/ndf/ghost.pdf']);

    $this->get(route('comptabilite.ndf.piece-jointe', [
        'noteDeFrais' => $this->ndf->id,
        'ligne' => $this->ligne->id,
    ]))->assertNotFound();
});
