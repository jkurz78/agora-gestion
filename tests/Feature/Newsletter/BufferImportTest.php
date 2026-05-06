<?php

declare(strict_types=1);

use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use Illuminate\Support\Facades\Schema;

it('migration adds the 4 admin processing columns to newsletter buffer', function () {
    expect(Schema::hasColumn('newsletter_subscription_requests', 'ignored_at'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'desinscription_traitee_at'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'desinscription_action'))->toBeTrue();
    expect(Schema::hasColumn('newsletter_subscription_requests', 'processed_by_user_id'))->toBeTrue();
});

it('scope inscriptionsAtraiter ne renvoie que les confirmed sans tiers_id ni ignored_at', function () {
    $tiers = Tiers::factory()->create();

    SubscriptionRequest::factory()->inscriptionAtraiter()->create(['email' => 'pending@x.fr']);
    SubscriptionRequest::factory()->importee($tiers->id)->create(['email' => 'imported@x.fr']);
    SubscriptionRequest::factory()->ignoree()->create(['email' => 'ignored@x.fr']);

    $emails = SubscriptionRequest::query()
        ->inscriptionsAtraiter()
        ->pluck('email')
        ->all();

    expect($emails)->toBe(['pending@x.fr']);
});

it('scope desinscriptionsAtraiter ne renvoie que les unsubscribed avec tiers_id et sans desinscription_traitee_at', function () {
    $tiers1 = Tiers::factory()->create();
    $tiers2 = Tiers::factory()->create();

    SubscriptionRequest::factory()->desinscriptionAtraiter($tiers1->id)->create(['email' => 'todo@x.fr']);
    SubscriptionRequest::factory()->desinscriptionTraitee($tiers2->id)->create(['email' => 'done@x.fr']);
    SubscriptionRequest::factory()->create([
        'status' => SubscriptionRequestStatus::Unsubscribed,
        'tiers_id' => null,
        'email' => 'orphan@x.fr',
    ]);

    $emails = SubscriptionRequest::query()
        ->desinscriptionsAtraiter()
        ->pluck('email')
        ->all();

    expect($emails)->toBe(['todo@x.fr']);
});
