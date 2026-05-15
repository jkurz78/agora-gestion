<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Tiers;
use App\Support\PortailRoute;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeAssoAndAlice(): array
{
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $alice = Tiers::factory()->create(['association_id' => $asso->id]);

    return [$asso, $alice];
}

function makeEmailLogWithAttachment(int $tiersId, string $path, string $content): EmailLog
{
    Storage::disk('local')->put($path, $content);

    return EmailLog::factory()->create([
        'tiers_id' => $tiersId,
        'attachment_path' => $path,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Téléchargement PJ — succès
// ─────────────────────────────────────────────────────────────────────────────
it('télécharge la PJ en 200 avec Content-Type et Content-Disposition inline', function () {
    [$asso, $alice] = makeAssoAndAlice();
    Auth::guard('tiers-portail')->login($alice);

    $pdfContent = '%PDF-1.4 fake content';
    $emailLog = makeEmailLogWithAttachment((int) $alice->id, 'test-attachment.pdf', $pdfContent);

    $url = PortailRoute::to('messages.attachment', $asso, ['emailLog' => $emailLog->id]);

    $response = $this->get($url);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->getContent())->toBe($pdfContent);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Sans PJ — 404
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 404 quand attachment_path est null', function () {
    [$asso, $alice] = makeAssoAndAlice();
    Auth::guard('tiers-portail')->login($alice);

    $emailLog = EmailLog::factory()->create([
        'tiers_id' => $alice->id,
        'attachment_path' => null,
    ]);

    $url = PortailRoute::to('messages.attachment', $asso, ['emailLog' => $emailLog->id]);

    $this->get($url)->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Fichier inexistant sur disque — 404
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 404 quand le fichier est absent du disque', function () {
    [$asso, $alice] = makeAssoAndAlice();
    Auth::guard('tiers-portail')->login($alice);

    $emailLog = EmailLog::factory()->create([
        'tiers_id' => $alice->id,
        'attachment_path' => 'nonexistent/file.pdf',
    ]);

    $url = PortailRoute::to('messages.attachment', $asso, ['emailLog' => $emailLog->id]);

    $this->get($url)->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Intrusion intra-asso — 403
// ─────────────────────────────────────────────────────────────────────────────
it('[sécurité] Alice ne peut pas télécharger la PJ de Bob dans la même asso', function () {
    [$asso, $alice] = makeAssoAndAlice();
    $bob = Tiers::factory()->create(['association_id' => $asso->id]);

    Auth::guard('tiers-portail')->login($alice);

    $emailLog = makeEmailLogWithAttachment((int) $bob->id, 'bob-attachment.pdf', '%PDF-1.4 bob');

    $url = PortailRoute::to('messages.attachment', $asso, ['emailLog' => $emailLog->id]);

    $this->get($url)->assertStatus(403);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Cross-tenant — 404
// ─────────────────────────────────────────────────────────────────────────────
it('[sécurité] Alice asso A ne peut pas télécharger la PJ d\'un EmailLog asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create(['association_id' => $assoA->id]);

    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    $emailLogB = makeEmailLogWithAttachment((int) $tiersB->id, 'asso-b-attachment.pdf', '%PDF-1.4 assoB');

    // Alice connectée sur assoA
    TenantContext::boot($assoA);
    Auth::guard('tiers-portail')->login($alice);

    $url = PortailRoute::to('messages.attachment', $assoA, ['emailLog' => $emailLogB->id]);

    // Tiers::find($emailLogB->tiers_id) sous TenantScope assoA → null → 404
    $this->get($url)->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Logger
// ─────────────────────────────────────────────────────────────────────────────
it('émet un log portail.message.attachment.telecharge sur succès', function () {
    [$asso, $alice] = makeAssoAndAlice();
    Auth::guard('tiers-portail')->login($alice);

    $emailLog = makeEmailLogWithAttachment((int) $alice->id, 'log-test.pdf', '%PDF-1.4 log test');

    $url = PortailRoute::to('messages.attachment', $asso, ['emailLog' => $emailLog->id]);

    Log::spy();

    $this->get($url)->assertStatus(200);

    Log::shouldHaveReceived('info')
        ->with('portail.message.attachment.telecharge', [
            'email_log_id' => $emailLog->id,
            'tiers_id' => $alice->id,
        ])
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Non authentifié — redirect login (middleware Authenticate)
// ─────────────────────────────────────────────────────────────────────────────
it('redirige vers login si aucun Tiers authentifié', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    $emailLog = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'attachment_path' => 'some/path.pdf',
    ]);

    $url = PortailRoute::to('messages.attachment', $asso, ['emailLog' => $emailLog->id]);

    $this->get($url)->assertRedirect();
});
