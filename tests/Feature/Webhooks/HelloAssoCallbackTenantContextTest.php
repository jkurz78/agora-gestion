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
