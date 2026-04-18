<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->operation = Operation::factory()->create();
    $this->seance = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('returns 404 when seance has no feuille attached', function () {
    $this->actingAs($this->user)
        ->get(route('operations.seances.feuille-signee.download', [$this->operation, $this->seance]))
        ->assertNotFound();
});

it('downloads the feuille PDF when attached', function () {
    $aid = $this->association->id;
    $sid = $this->seance->id;
    Storage::disk('local')->put("associations/{$aid}/seances/{$sid}/feuille-signee.pdf", 'PDF CONTENT');
    $this->seance->update([
        'feuille_signee_path' => 'feuille-signee.pdf',
        'feuille_signee_at' => now(),
        'feuille_signee_source' => 'manual',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('operations.seances.feuille-signee.download', [$this->operation, $this->seance]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('feuille-signee-seance-1.pdf');
});

it('returns 404 when seance does not belong to the operation', function () {
    $otherOperation = Operation::factory()->create();
    $aid = $this->association->id;
    $sid = $this->seance->id;
    Storage::disk('local')->put("associations/{$aid}/seances/{$sid}/feuille-signee.pdf", 'PDF CONTENT');
    $this->seance->update([
        'feuille_signee_path' => 'feuille-signee.pdf',
        'feuille_signee_at' => now(),
        'feuille_signee_source' => 'manual',
    ]);

    // Pass otherOperation in the URL with the seance from the original operation
    $this->actingAs($this->user)
        ->get(route('operations.seances.feuille-signee.download', [$otherOperation, $this->seance]))
        ->assertNotFound();
});

it('redirects guests to login', function () {
    $aid = $this->association->id;
    $sid = $this->seance->id;
    Storage::disk('local')->put("associations/{$aid}/seances/{$sid}/feuille-signee.pdf", 'PDF CONTENT');
    $this->seance->update([
        'feuille_signee_path' => 'feuille-signee.pdf',
        'feuille_signee_at' => now(),
        'feuille_signee_source' => 'manual',
    ]);

    $this->get(route('operations.seances.feuille-signee.download', [$this->operation, $this->seance]))
        ->assertRedirect(route('login'));
});
