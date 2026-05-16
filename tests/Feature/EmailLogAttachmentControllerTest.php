<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

// ── Téléchargement PJ — succès ───────────────────────────────────────────────

it('télécharge la PJ en 200 avec Content-Type et Content-Disposition inline', function () {
    $tiers = Tiers::factory()->create();
    $pdf = '%PDF-1.4 fake';
    Storage::disk('local')->put('email_attachments/test.pdf', $pdf);

    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'attachment_path' => 'email_attachments/test.pdf',
    ]);

    $admin = User::factory()->create();

    $response = $this->actingAs($admin)
        ->get(route('tiers.email-logs.attachment', ['emailLog' => $log->id]));

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->getContent())->toBe($pdf);
});

// ── 404 quand pas de PJ ──────────────────────────────────────────────────────

it('retourne 404 quand attachment_path est null', function () {
    $tiers = Tiers::factory()->create();
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'attachment_path' => null,
    ]);
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('tiers.email-logs.attachment', ['emailLog' => $log->id]))
        ->assertStatus(404);
});

// ── 404 cross-tenant ─────────────────────────────────────────────────────────

it('retourne 404 quand l\'email_log appartient à un autre tenant', function () {
    // Tenant A — défaut booté par le beforeEach global
    $tiersA = Tiers::factory()->create();
    $adminA = User::factory()->create();

    // Tenant B — crée puis booke pour l'EmailLog
    $assoB = Association::factory()->create();
    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    Storage::disk('local')->put('email_attachments/cross.pdf', 'x');
    $logB = EmailLog::factory()->create([
        'tiers_id' => $tiersB->id,
        'attachment_path' => 'email_attachments/cross.pdf',
    ]);

    // On rebascule sur le tenant A (admin A authentifié) pour faire la requête
    $assoA = $tiersA->association;
    TenantContext::boot($assoA);

    $this->actingAs($adminA)
        ->get(route('tiers.email-logs.attachment', ['emailLog' => $logB->id]))
        ->assertStatus(404);
});

// ── 404 fichier disparu du disque ────────────────────────────────────────────

it('retourne 404 quand le fichier n\'existe pas sur le disque', function () {
    $tiers = Tiers::factory()->create();
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'attachment_path' => 'email_attachments/missing.pdf', // pas mis sur disque
    ]);
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('tiers.email-logs.attachment', ['emailLog' => $log->id]))
        ->assertStatus(404);
});

// ── Redirige vers login quand non authentifié ────────────────────────────────

it('redirige vers login quand non authentifié', function () {
    $tiers = Tiers::factory()->create();
    Storage::disk('local')->put('email_attachments/test.pdf', '%PDF');
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'attachment_path' => 'email_attachments/test.pdf',
    ]);

    $this->get(route('tiers.email-logs.attachment', ['emailLog' => $log->id]))
        ->assertRedirect(route('login'));
});
