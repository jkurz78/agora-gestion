<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\HelloAssoNotification;
use App\Models\HelloAssoParametres;
use App\Tenant\TenantContext;

afterEach(function () {
    TenantContext::clear();
});

it('boots TenantContext for the resolved association during the webhook', function () {
    $asso = Association::factory()->create();

    // callback_token has 'encrypted' cast — store plain, Eloquent encodes on save, decodes on read.
    HelloAssoParametres::create([
        'association_id' => $asso->id,
        'callback_token' => 'token-xyz',
    ]);

    $observed = null;

    // Observe via a model event listener on HelloAssoNotification::created
    HelloAssoNotification::created(function ($notification) use (&$observed) {
        $observed = TenantContext::currentId();
    });

    // Route is in routes/api.php with prefix /api/helloasso/callback/{token}
    $response = $this->postJson('/api/helloasso/callback/token-xyz', [
        'eventType' => 'Order',
        'data' => ['formType' => 'Donation', 'name' => 'Bob'],
    ]);

    $response->assertOk();
    expect($observed)->toBe($asso->id);
});

it('résout le token hors scope puis écrit uniquement sous le tenant correspondant', function (): void {
    $associationA = Association::factory()->create();
    $associationB = Association::factory()->create();
    HelloAssoParametres::create([
        'association_id' => (int) $associationA->id,
        'callback_token' => 'token-a',
    ]);
    HelloAssoParametres::create([
        'association_id' => (int) $associationB->id,
        'callback_token' => 'token-b',
    ]);

    $this->postJson('/api/helloasso/callback/token-b', [
        'eventType' => 'Order',
        'data' => ['formType' => 'Donation', 'name' => 'Tenant B'],
    ])->assertOk();

    expect(DB::table('helloasso_notifications')->count())->toBe(1)
        ->and((int) DB::table('helloasso_notifications')->value('association_id'))
        ->toBe((int) $associationB->id);

    $this->postJson('/api/helloasso/callback/token-invalide', ['eventType' => 'Order'])
        ->assertForbidden();
    expect(DB::table('helloasso_notifications')->count())->toBe(1);
});
