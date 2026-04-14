<?php

declare(strict_types=1);

use App\Models\EmailLog;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('GET affiche la page de confirmation sans effectuer le opt-out', function () {
    $tiers = Tiers::factory()->create(['email' => 'test@example.com', 'email_optout' => false]);
    $user = User::factory()->create();

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'categorie' => 'communication',
        'destinataire_email' => 'test@example.com',
        'destinataire_nom' => 'Test',
        'objet' => 'Test',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
        'tracking_token' => 'valid-token-123',
    ]);

    $response = $this->get('/email/optout/valid-token-123');

    $response->assertOk();
    $response->assertSee('Confirmer la désinscription');
    // Pas encore désinscrit — le GET ne modifie rien
    expect($tiers->fresh()->email_optout)->toBeFalse();
});

it('POST effectue le opt-out et affiche la confirmation', function () {
    $tiers = Tiers::factory()->create(['email' => 'test@example.com', 'email_optout' => false]);
    $user = User::factory()->create();

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'categorie' => 'communication',
        'destinataire_email' => 'test@example.com',
        'destinataire_nom' => 'Test',
        'objet' => 'Test',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
        'tracking_token' => 'valid-token-123',
    ]);

    $response = $this->post('/email/optout/valid-token-123');

    $response->assertOk();
    $response->assertSee('désinscrit');
    expect($tiers->fresh()->email_optout)->toBeTrue();
});

it('returns 404 for unknown token', function () {
    $response = $this->get('/email/optout/unknown-token');
    $response->assertNotFound();
});

it('handles already opted-out tiers gracefully via POST', function () {
    $tiers = Tiers::factory()->create(['email_optout' => true]);
    $user = User::factory()->create();

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'categorie' => 'communication',
        'destinataire_email' => 'test@example.com',
        'destinataire_nom' => 'Test',
        'objet' => 'Test',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
        'tracking_token' => 'already-opted-out',
    ]);

    $response = $this->post('/email/optout/already-opted-out');
    $response->assertOk();
    expect($tiers->fresh()->email_optout)->toBeTrue();
});

it('does not require authentication', function () {
    $tiers = Tiers::factory()->create();
    $user = User::factory()->create();

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'categorie' => 'communication',
        'destinataire_email' => 'test@example.com',
        'destinataire_nom' => 'Test',
        'objet' => 'Test',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
        'tracking_token' => 'public-token',
    ]);

    // No actingAs — anonymous request
    $response = $this->get('/email/optout/public-token');
    $response->assertOk();
});

it('resubscribes a tiers via resubscribe link', function () {
    $tiers = Tiers::factory()->create(['email' => 'test@example.com', 'email_optout' => true]);
    $user = User::factory()->create();

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'categorie' => 'communication',
        'destinataire_email' => 'test@example.com',
        'destinataire_nom' => 'Test',
        'objet' => 'Test',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
        'tracking_token' => 'resub-token',
    ]);

    $response = $this->get('/email/resubscribe/resub-token');

    $response->assertOk();
    $response->assertSee('inscrit');
    expect($tiers->fresh()->email_optout)->toBeFalse();
});
