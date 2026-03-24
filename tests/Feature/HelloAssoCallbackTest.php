<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\HelloAssoNotification;
use App\Models\HelloAssoParametres;

beforeEach(function () {
    $association = Association::first() ?? Association::create([
        'nom' => 'Test Association',
    ]);
    $this->parametres = HelloAssoParametres::create([
        'association_id' => $association->id,
        'callback_token' => 'test-token-abc123',
        'environnement' => 'sandbox',
    ]);
});

test('callback avec token valide crée une notification', function () {
    $payload = [
        'eventType' => 'Order',
        'data' => [
            'formType' => 'Membership',
            'formSlug' => 'cotisation-2026',
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont'],
        ],
    ];

    $response = $this->postJson('/api/helloasso/callback/test-token-abc123', $payload);

    $response->assertOk();
    expect(HelloAssoNotification::count())->toBe(1);

    $notif = HelloAssoNotification::first();
    expect($notif->event_type)->toBe('Order');
    expect($notif->libelle)->toContain('cotisation');
    expect($notif->libelle)->toContain('Jean Dupont');
    expect($notif->payload)->toEqual($payload);
});

test('callback avec token invalide retourne 403', function () {
    $response = $this->postJson('/api/helloasso/callback/wrong-token', ['eventType' => 'Order']);

    $response->assertForbidden();
    expect(HelloAssoNotification::count())->toBe(0);
});

test('callback don génère le bon libellé', function () {
    $payload = [
        'eventType' => 'Order',
        'data' => [
            'formType' => 'Donation',
            'formName' => 'Dons libres',
            'payer' => ['firstName' => 'Marie', 'lastName' => 'Martin'],
        ],
    ];

    $this->postJson('/api/helloasso/callback/test-token-abc123', $payload);

    $notif = HelloAssoNotification::first();
    expect($notif->libelle)->toContain('Nouveau don');
    expect($notif->libelle)->toContain('Marie Martin');
});

test('callback sans données payeur génère un libellé sans nom', function () {
    $payload = [
        'eventType' => 'Form',
        'data' => ['formSlug' => 'mon-formulaire'],
    ];

    $this->postJson('/api/helloasso/callback/test-token-abc123', $payload);

    $notif = HelloAssoNotification::first();
    expect($notif->libelle)->toBe('Modification formulaire');
});
