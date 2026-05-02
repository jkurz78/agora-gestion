<?php

declare(strict_types=1);

use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Models\Newsletter\SubscriptionRequest;
use Illuminate\Support\Facades\Schema;

it('creates the newsletter_subscription_requests table with all expected columns', function () {
    expect(Schema::hasTable('newsletter_subscription_requests'))->toBeTrue();

    expect(Schema::hasColumns('newsletter_subscription_requests', [
        'id',
        'association_id',
        'email',
        'prenom',
        'status',
        'confirmation_token_hash',
        'confirmation_expires_at',
        'unsubscribe_token_hash',
        'subscribed_at',
        'confirmed_at',
        'unsubscribed_at',
        'ip_address',
        'user_agent',
        'tiers_id',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('creates a SubscriptionRequest via factory with default pending status', function () {
    $r = SubscriptionRequest::factory()->create(['email' => 'alice@example.fr']);

    expect($r->email)->toBe('alice@example.fr');
    expect($r->status)->toBe(SubscriptionRequestStatus::Pending);
    expect((int) $r->association_id)->toBe((int) \App\Tenant\TenantContext::currentId());
});

it('scope active() returns only confirmed rows', function () {
    SubscriptionRequest::factory()->create(['status' => SubscriptionRequestStatus::Pending]);
    $confirmed = SubscriptionRequest::factory()->create(['status' => SubscriptionRequestStatus::Confirmed]);
    SubscriptionRequest::factory()->create(['status' => SubscriptionRequestStatus::Unsubscribed]);

    $active = SubscriptionRequest::active()->get();

    expect($active)->toHaveCount(1);
    expect($active->first()->id)->toBe($confirmed->id);
});
