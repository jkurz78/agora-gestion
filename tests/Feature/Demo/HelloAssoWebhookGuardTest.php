<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\HelloAssoNotification;
use App\Models\HelloAssoParametres;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $association = Association::first() ?? Association::factory()->create(['nom' => 'Test Asso']);
    $this->parametres = HelloAssoParametres::create([
        'association_id' => $association->id,
        'callback_token' => 'demo-guard-token',
        'environnement' => 'sandbox',
    ]);
});

afterEach(function () {
    TenantContext::clear();
    app()->detectEnvironment(fn (): string => 'testing');
});

it('returns 200 no-op and emits log in demo env without persisting notification', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    Log::shouldReceive('info')
        ->once()
        ->with('helloasso.webhook.skipped_demo', Mockery::on(fn ($ctx) => array_key_exists('payload_keys', $ctx)));

    $payload = [
        'eventType' => 'Order',
        'data' => [
            'formType' => 'Membership',
            'formSlug' => 'cotisation-2026',
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont'],
        ],
    ];

    $response = $this->postJson('/api/helloasso/callback/demo-guard-token', $payload);

    $response->assertOk();
    $response->assertJson(['status' => 'skipped_demo']);
    expect(HelloAssoNotification::count())->toBe(0);
});

it('persists notification normally in non-demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $payload = [
        'eventType' => 'Order',
        'data' => [
            'formType' => 'Membership',
            'formSlug' => 'cotisation-2026',
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont'],
        ],
    ];

    $response = $this->postJson('/api/helloasso/callback/demo-guard-token', $payload);

    $response->assertOk();
    $response->assertJson(['status' => 'ok']);
    expect(HelloAssoNotification::count())->toBe(1);
});
