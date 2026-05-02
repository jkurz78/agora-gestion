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

// ─── Tasks 9–11 : Middleware Origin + Controller HTTP ────────────────────────

use App\Models\Association;

beforeEach(function () {
    // Récupère l'asso bootée par tests/Pest.php et la rend résoluble par slug
    $association = TenantContext::current();
    if ($association && ! $association->slug) {
        $association->slug = 'soigner-vivre-sourire';
        $association->save();
    } else {
        $association?->update(['slug' => 'soigner-vivre-sourire']);
    }

    config(['newsletter.origins' => [
        'https://soigner-vivre-sourire.fr' => 'soigner-vivre-sourire',
        'http://localhost:4321'            => 'soigner-vivre-sourire',
    ]]);
});

it('rejects POST /api/newsletter/subscribe from an unauthorized origin (403)', function () {
    Mail::fake();

    $response = $this->withHeaders([
        'Origin'       => 'https://attaquant.example',
        'Content-Type' => 'application/json',
    ])->postJson('/api/newsletter/subscribe', [
        'email'    => 'alice@example.fr',
        'consent'  => true,
        'bot_trap' => '',
    ]);

    $response->assertStatus(403);
    expect(SubscriptionRequest::count())->toBe(0);
    Mail::assertNothingSent();
});

it('POST /api/newsletter/subscribe with valid payload returns 200 and creates a pending row', function () {
    Mail::fake();

    $response = $this->withHeaders([
        'Origin' => 'https://soigner-vivre-sourire.fr',
    ])->postJson('/api/newsletter/subscribe', [
        'email'    => 'alice@example.fr',
        'prenom'   => 'Alice',
        'consent'  => true,
        'bot_trap' => '',
    ]);

    $response->assertStatus(200)->assertJson(['status' => 'pending_double_optin']);

    $row = SubscriptionRequest::where('email', 'alice@example.fr')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(SubscriptionRequestStatus::Pending);
    expect((int) $row->association_id)->toBe((int) TenantContext::currentId());

    Mail::assertSent(NewsletterConfirmation::class);
});

it('POST with malformed email returns 422 with validation_failed shape', function () {
    $response = $this->withHeaders([
        'Origin' => 'https://soigner-vivre-sourire.fr',
    ])->postJson('/api/newsletter/subscribe', [
        'email'    => 'pas-un-email',
        'consent'  => true,
        'bot_trap' => '',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['error', 'fields' => ['email']])
        ->assertJson(['error' => 'validation_failed']);
});

it('POST without consent returns 422', function () {
    $response = $this->withHeaders([
        'Origin' => 'https://soigner-vivre-sourire.fr',
    ])->postJson('/api/newsletter/subscribe', [
        'email'    => 'alice@example.fr',
        'consent'  => false,
        'bot_trap' => '',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['fields' => ['consent']]);
});

it('POST with filled bot_trap returns 200 silently with no row and no mail', function () {
    Mail::fake();

    $response = $this->withHeaders([
        'Origin' => 'https://soigner-vivre-sourire.fr',
    ])->postJson('/api/newsletter/subscribe', [
        'email'    => 'bot@spam.com',
        'consent'  => true,
        'bot_trap' => 'http://link-spam',
    ]);

    $response->assertStatus(200)->assertJson(['status' => 'pending_double_optin']);
    expect(SubscriptionRequest::count())->toBe(0);
    Mail::assertNothingSent();
});

it('rate-limits to 5 requests per IP per hour', function () {
    Mail::fake();

    for ($i = 1; $i <= 5; $i++) {
        $this->withHeaders([
            'Origin'          => 'https://soigner-vivre-sourire.fr',
            'X-Forwarded-For' => '1.2.3.4',
        ])->postJson('/api/newsletter/subscribe', [
            'email'    => "user{$i}@example.fr",
            'consent'  => true,
            'bot_trap' => '',
        ])->assertStatus(200);
    }

    $sixth = $this->withHeaders([
        'Origin'          => 'https://soigner-vivre-sourire.fr',
        'X-Forwarded-For' => '1.2.3.4',
    ])->postJson('/api/newsletter/subscribe', [
        'email'    => 'user6@example.fr',
        'consent'  => true,
        'bot_trap' => '',
    ]);

    $sixth->assertStatus(429)->assertJson(['error' => 'rate_limit']);
});
