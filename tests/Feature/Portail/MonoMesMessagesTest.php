<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Tiers;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();
    Storage::fake('local');
});

afterEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helper
// ─────────────────────────────────────────────────────────────────────────────

function monoMessagesSetup(): array
{
    $asso = Association::factory()->create(['slug' => 'svs']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    return [$asso, $tiers];
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Mode mono + Tiers connecté GET /portail/mes-messages → 200
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/mes-messages retourne 200 avec EmailLog affiché', function () {
    [$asso, $tiers] = monoMessagesSetup();

    $emailLog = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'objet' => 'Bienvenue dans le portail',
        'created_at' => now()->subDay(),
    ]);

    $this->get('/portail/mes-messages')
        ->assertStatus(200)
        ->assertSeeText('Bienvenue dans le portail');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Téléchargement PJ depuis mode mono → 200 + Content-Type valide
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/messages/attachment/{emailLog} sert le fichier', function () {
    [$asso, $tiers] = monoMessagesSetup();

    $pdfContent = '%PDF-1.4 fake mono content';
    $path = 'test-mono-attachment.pdf';
    Storage::disk('local')->put($path, $pdfContent);

    $emailLog = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'attachment_path' => $path,
    ]);

    $response = $this->get("/portail/messages/attachment/{$emailLog->id}");

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->getContent())->toBe($pdfContent);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Empty state mode mono → 200 + message muted
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/mes-messages affiche empty state quand aucun message', function () {
    monoMessagesSetup();

    $this->get('/portail/mes-messages')
        ->assertStatus(200)
        ->assertSee('pas encore reçu de message');
});
