<?php

declare(strict_types=1);

use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Mail\NewsletterConfirmation;
use App\Models\Newsletter\SubscriptionRequest;
use App\Services\Newsletter\SubscriptionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
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
    expect((int) $r->association_id)->toBe((int) TenantContext::currentId());
});

it('scope active() returns only confirmed rows', function () {
    SubscriptionRequest::factory()->create(['status' => SubscriptionRequestStatus::Pending]);
    $confirmed = SubscriptionRequest::factory()->create(['status' => SubscriptionRequestStatus::Confirmed]);
    SubscriptionRequest::factory()->create(['status' => SubscriptionRequestStatus::Unsubscribed]);

    $active = SubscriptionRequest::active()->get();

    expect($active)->toHaveCount(1);
    expect($active->first()->id)->toBe($confirmed->id);
});

it('regenerateConfirmationToken returns clear token and stores hash + expiry', function () {
    $r = SubscriptionRequest::factory()->create();

    $clear = $r->regenerateConfirmationToken();
    $r->save();

    expect($clear)->toBeString();
    expect(strlen($clear))->toBeGreaterThanOrEqual(40);
    expect($r->confirmation_token_hash)->toBe(hash('sha256', $clear));
    expect($r->confirmation_expires_at)->not->toBeNull();
    expect($r->confirmation_expires_at->isFuture())->toBeTrue();
});

it('regenerateUnsubscribeToken returns clear token and stores hash', function () {
    $r = SubscriptionRequest::factory()->create();

    $clear = $r->regenerateUnsubscribeToken();
    $r->save();

    expect($clear)->toBeString();
    expect($r->unsubscribe_token_hash)->toBe(hash('sha256', $clear));
});

it('markConfirmed sets status and confirmed_at', function () {
    $r = SubscriptionRequest::factory()->create([
        'status' => SubscriptionRequestStatus::Pending,
    ]);

    $r->markConfirmed();
    $r->save();

    expect($r->status)->toBe(SubscriptionRequestStatus::Confirmed);
    expect($r->confirmed_at)->not->toBeNull();
});

it('markUnsubscribed sets status and unsubscribed_at', function () {
    $r = SubscriptionRequest::factory()->create([
        'status' => SubscriptionRequestStatus::Confirmed,
    ]);

    $r->markUnsubscribed();
    $r->save();

    expect($r->status)->toBe(SubscriptionRequestStatus::Unsubscribed);
    expect($r->unsubscribed_at)->not->toBeNull();
});

// ─── Task 5 : Service subscribe (nouveau email) ───────────────────────────────

it('subscribe creates a pending row and sends confirmation email for a new email', function () {
    Mail::fake();

    $service = app(SubscriptionService::class);
    $service->subscribe('alice@example.fr', 'Alice', '1.2.3.4', 'Mozilla/5.0');

    $row = SubscriptionRequest::where('email', 'alice@example.fr')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(SubscriptionRequestStatus::Pending);
    expect($row->prenom)->toBe('Alice');
    expect($row->ip_address)->toBe('1.2.3.4');
    expect($row->user_agent)->toBe('Mozilla/5.0');
    expect($row->confirmation_token_hash)->not->toBeNull();
    expect($row->unsubscribe_token_hash)->not->toBeNull();
    expect($row->confirmation_expires_at->isFuture())->toBeTrue();

    Mail::assertSent(NewsletterConfirmation::class, function (NewsletterConfirmation $mail) {
        return $mail->hasTo('alice@example.fr');
    });
});

// ─── Task 6 : Idempotence ─────────────────────────────────────────────────────

it('subscribe is idempotent for a pending duplicate (rotates token, resends mail)', function () {
    Mail::fake();
    $service = app(SubscriptionService::class);

    $service->subscribe('bob@example.fr', 'Bob', '1.2.3.4', 'UA');
    $first = SubscriptionRequest::where('email', 'bob@example.fr')->firstOrFail();
    $firstHash = $first->confirmation_token_hash;
    $firstExpiry = $first->confirmation_expires_at;

    // Avance le temps pour observer la rotation d'expiry
    \Illuminate\Support\Carbon::setTestNow(now()->addMinute());

    $service->subscribe('bob@example.fr', 'Bob', '1.2.3.4', 'UA');

    expect(SubscriptionRequest::where('email', 'bob@example.fr')->count())->toBe(1);
    $second = SubscriptionRequest::where('email', 'bob@example.fr')->firstOrFail();
    expect($second->id)->toBe($first->id);
    expect($second->confirmation_token_hash)->not->toBe($firstHash);
    expect($second->confirmation_expires_at)->not->toEqual($firstExpiry);

    Mail::assertSent(NewsletterConfirmation::class, 2);
});

it('subscribe is silent for a confirmed duplicate (no mutation, no mail)', function () {
    Mail::fake();
    $service = app(SubscriptionService::class);

    $existing = SubscriptionRequest::factory()->create([
        'email'  => 'carol@example.fr',
        'status' => SubscriptionRequestStatus::Confirmed,
    ]);
    $existingUpdatedAt = $existing->updated_at;

    \Illuminate\Support\Carbon::setTestNow(now()->addMinute());

    $service->subscribe('carol@example.fr', 'Carol', '1.2.3.4', 'UA');

    $still = SubscriptionRequest::where('email', 'carol@example.fr')->firstOrFail();
    expect($still->id)->toBe($existing->id);
    expect($still->status)->toBe(SubscriptionRequestStatus::Confirmed);
    expect($still->updated_at->equalTo($existingUpdatedAt))->toBeTrue();

    Mail::assertNothingSent();
});

it('subscribe creates a NEW row for a previously unsubscribed email (preserves history)', function () {
    Mail::fake();
    $service = app(SubscriptionService::class);

    $past = SubscriptionRequest::factory()->create([
        'email'  => 'dave@example.fr',
        'status' => SubscriptionRequestStatus::Unsubscribed,
    ]);

    $service->subscribe('dave@example.fr', 'Dave', '1.2.3.4', 'UA');

    expect(SubscriptionRequest::where('email', 'dave@example.fr')->count())->toBe(2);
    expect(SubscriptionRequest::find($past->id)->status)
        ->toBe(SubscriptionRequestStatus::Unsubscribed);
    expect(
        SubscriptionRequest::where('email', 'dave@example.fr')
            ->where('status', SubscriptionRequestStatus::Pending)
            ->exists()
    )->toBeTrue();

    Mail::assertSent(NewsletterConfirmation::class, 1);
});

// ─── Task 7 : Mailable — liens en clair (pas les hashes) ─────────────────────

it('confirmation email contains clear-text confirm and unsubscribe URLs (not hashes)', function () {
    Mail::fake();
    $service = app(SubscriptionService::class);

    $service->subscribe('erin@example.fr', 'Erin', '1.2.3.4', 'UA');

    Mail::assertSent(NewsletterConfirmation::class, function (NewsletterConfirmation $mail) {
        $row = SubscriptionRequest::where('email', 'erin@example.fr')->firstOrFail();

        // Le mail expose les tokens en clair dans ses propriétés
        expect($mail->confirmationToken)->toBeString();
        expect($mail->unsubscribeToken)->toBeString();

        // Les hash en DB doivent correspondre aux tokens clairs
        expect($row->confirmation_token_hash)->toBe(hash('sha256', $mail->confirmationToken));
        expect($row->unsubscribe_token_hash)->toBe(hash('sha256', $mail->unsubscribeToken));

        // Le rendu HTML contient les liens clairs
        $html = $mail->render();
        expect($html)->toContain('/newsletter/confirm/' . $mail->confirmationToken);
        expect($html)->toContain('/newsletter/unsubscribe/' . $mail->unsubscribeToken);

        // Et NE contient PAS les hash
        expect($html)->not->toContain($row->confirmation_token_hash);
        expect($html)->not->toContain($row->unsubscribe_token_hash);

        return true;
    });
});
