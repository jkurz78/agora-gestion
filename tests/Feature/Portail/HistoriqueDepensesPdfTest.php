<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');

    $this->asso = Association::factory()->create(['slug' => 'hist-asso']);
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => true,
    ]);
    Auth::guard('tiers-portail')->login($this->tiers);
    session(['portail.last_activity_at' => now()->timestamp]);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helper : crée une Transaction de dépense avec pièce jointe sur disque local
// ---------------------------------------------------------------------------

function makeDepenseAvecPj(Association $asso, Tiers $tiers): Transaction
{
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => 'facture.pdf',
        'piece_jointe_nom' => 'Facture test.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $fullPath = $tx->pieceJointeFullPath();
    Storage::disk('local')->put($fullPath, '%PDF-1.4 fake content');

    return $tx;
}

// ---------------------------------------------------------------------------
// Scénario 6 : GET PDF Transaction — Tiers authentifié, signed URL valide → 200
// ---------------------------------------------------------------------------

it('[historique-pdf] Tiers authentifié avec signed URL valide obtient le PDF → 200', function () {
    $tx = makeDepenseAvecPj($this->asso, $this->tiers);

    $signedUrl = URL::signedRoute('portail.historique.pdf', [
        'association' => $this->asso->slug,
        'transaction' => $tx->id,
    ]);

    $this->get($signedUrl)
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

// ---------------------------------------------------------------------------
// Scénario 7 : GET PDF Transaction — sans pièce jointe → 404
// ---------------------------------------------------------------------------

it('[historique-pdf] Transaction sans pièce jointe → 404', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'piece_jointe_path' => null,
    ]);

    $signedUrl = URL::signedRoute('portail.historique.pdf', [
        'association' => $this->asso->slug,
        'transaction' => $tx->id,
    ]);

    $this->get($signedUrl)->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Scénario 8 : GET PDF Transaction — type recette → 404 (sécurité)
// ---------------------------------------------------------------------------

it('[historique-pdf] Transaction de type recette → 404', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'piece_jointe_path' => 'recu.pdf',
        'piece_jointe_nom' => 'Reçu.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);
    $fullPath = $tx->pieceJointeFullPath();
    Storage::disk('local')->put($fullPath, '%PDF-1.4 fake content');

    $signedUrl = URL::signedRoute('portail.historique.pdf', [
        'association' => $this->asso->slug,
        'transaction' => $tx->id,
    ]);

    $this->get($signedUrl)->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Scénario 9 : GET PDF Transaction — autre Tiers même tenant → 403
// ---------------------------------------------------------------------------

it('[historique-pdf] autre Tiers même tenant → 403', function () {
    $autreTiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => true,
    ]);

    // Transaction appartient à autreTiers
    $tx = makeDepenseAvecPj($this->asso, $autreTiers);

    $signedUrl = URL::signedRoute('portail.historique.pdf', [
        'association' => $this->asso->slug,
        'transaction' => $tx->id,
    ]);

    // $this->tiers est connecté mais n'est pas propriétaire → 403
    $this->get($signedUrl)->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Scénario bonus : URL sans signature → 403
// ---------------------------------------------------------------------------

it('[historique-pdf] URL sans signature → 403', function () {
    $tx = makeDepenseAvecPj($this->asso, $this->tiers);

    $url = route('portail.historique.pdf', [
        'association' => $this->asso->slug,
        'transaction' => $tx->id,
    ]);

    $this->get($url)->assertStatus(403);
});
