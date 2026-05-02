<?php

declare(strict_types=1);

use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Mail\NewsletterConfirmation;
use App\Models\Association;
use App\Models\Newsletter\SubscriptionRequest;
use App\Services\Newsletter\SubscriptionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Carbon;
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
    Carbon::setTestNow(now()->addMinute());

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
        'email' => 'carol@example.fr',
        'status' => SubscriptionRequestStatus::Confirmed,
    ]);
    $existingUpdatedAt = $existing->updated_at;

    Carbon::setTestNow(now()->addMinute());

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
        'email' => 'dave@example.fr',
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
        expect($html)->toContain('/newsletter/confirm/'.$mail->confirmationToken);
        expect($html)->toContain('/newsletter/unsubscribe/'.$mail->unsubscribeToken);

        // Et NE contient PAS les hash
        expect($html)->not->toContain($row->confirmation_token_hash);
        expect($html)->not->toContain($row->unsubscribe_token_hash);

        return true;
    });
});

// ─── Tasks 9–11 : Middleware Origin + Controller HTTP ────────────────────────

beforeEach(function () {
    // Récupère l'asso bootée par tests/Pest.php et la rend résoluble par slug.
    // allowSlugChange = true est nécessaire pour contourner ImmutableSlugObserver
    // (le slug factory est auto-généré ; on l'écrase ici pour le test).
    $association = TenantContext::current();
    if ($association) {
        $association->allowSlugChange = true;
        $association->slug = 'soigner-vivre-sourire';
        $association->save();
    }

    config(['newsletter.origins' => [
        'https://soigner-vivre-sourire.fr' => 'soigner-vivre-sourire',
        'http://localhost:4321' => 'soigner-vivre-sourire',
    ]]);
});

it('rejects POST /api/newsletter/subscribe from an unauthorized origin (403)', function () {
    Mail::fake();

    $response = $this->withHeaders([
        'Origin' => 'https://attaquant.example',
        'Content-Type' => 'application/json',
    ])->postJson('/api/newsletter/subscribe', [
        'email' => 'alice@example.fr',
        'consent' => true,
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
        'email' => 'alice@example.fr',
        'prenom' => 'Alice',
        'consent' => true,
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
        'email' => 'pas-un-email',
        'consent' => true,
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
        'email' => 'alice@example.fr',
        'consent' => false,
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
        'email' => 'bot@spam.com',
        'consent' => true,
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
            'Origin' => 'https://soigner-vivre-sourire.fr',
            'X-Forwarded-For' => '1.2.3.4',
        ])->postJson('/api/newsletter/subscribe', [
            'email' => "user{$i}@example.fr",
            'consent' => true,
            'bot_trap' => '',
        ])->assertStatus(200);
    }

    $sixth = $this->withHeaders([
        'Origin' => 'https://soigner-vivre-sourire.fr',
        'X-Forwarded-For' => '1.2.3.4',
    ])->postJson('/api/newsletter/subscribe', [
        'email' => 'user6@example.fr',
        'consent' => true,
        'bot_trap' => '',
    ]);

    $sixth->assertStatus(429)->assertJson(['error' => 'rate_limit']);
});

// ─── Task 12 : Routes web confirm/unsubscribe ─────────────────────────────────

it('GET /newsletter/confirm/{token} with valid token marks confirmed and renders thank-you page', function () {
    Mail::fake();

    // Inscription pour récupérer un token clair
    $service = app(SubscriptionService::class);
    $service->subscribe('frank@example.fr', 'Frank', '1.2.3.4', 'UA');

    $sentMail = collect(Mail::sent(NewsletterConfirmation::class))->first();
    $clearToken = $sentMail->confirmationToken;

    $response = $this->get('/newsletter/confirm/'.$clearToken);

    $response->assertStatus(200)->assertSee('Inscription confirmée');

    $row = SubscriptionRequest::where('email', 'frank@example.fr')->firstOrFail();
    expect($row->status)->toBe(SubscriptionRequestStatus::Confirmed);
    expect($row->confirmed_at)->not->toBeNull();
});

it('GET /newsletter/confirm/{token} with expired token returns 410', function () {
    Mail::fake();
    $service = app(SubscriptionService::class);
    $service->subscribe('grace@example.fr', 'Grace', '1.2.3.4', 'UA');

    $sentMail = collect(Mail::sent(NewsletterConfirmation::class))->first();
    $clearToken = $sentMail->confirmationToken;

    // Expire la ligne en DB
    SubscriptionRequest::where('email', 'grace@example.fr')->update([
        'confirmation_expires_at' => now()->subHour(),
    ]);

    $response = $this->get('/newsletter/confirm/'.$clearToken);

    $response->assertStatus(410)->assertSee('expiré');

    $row = SubscriptionRequest::where('email', 'grace@example.fr')->firstOrFail();
    expect($row->status)->toBe(SubscriptionRequestStatus::Pending);
});

it('GET /newsletter/confirm/{unknown-token} returns 404', function () {
    $response = $this->get('/newsletter/confirm/'.str_repeat('x', 48));
    $response->assertStatus(404);
});

it('GET /newsletter/unsubscribe/{token} from confirmed row marks unsubscribed', function () {
    Mail::fake();
    $service = app(SubscriptionService::class);
    $service->subscribe('hank@example.fr', 'Hank', '1.2.3.4', 'UA');

    $sentMail = collect(Mail::sent(NewsletterConfirmation::class))->first();
    $clearConfirm = $sentMail->confirmationToken;
    $clearUnsub = $sentMail->unsubscribeToken;

    $this->get('/newsletter/confirm/'.$clearConfirm)->assertStatus(200);

    $response = $this->get('/newsletter/unsubscribe/'.$clearUnsub);
    $response->assertStatus(200)->assertSee('désinscrit');

    $row = SubscriptionRequest::where('email', 'hank@example.fr')->firstOrFail();
    expect($row->status)->toBe(SubscriptionRequestStatus::Unsubscribed);
});

it('GET /newsletter/unsubscribe/{token} from pending row marks unsubscribed (RGPD pre-confirmation)', function () {
    Mail::fake();
    $service = app(SubscriptionService::class);
    $service->subscribe('ivy@example.fr', 'Ivy', '1.2.3.4', 'UA');

    $sentMail = collect(Mail::sent(NewsletterConfirmation::class))->first();
    $clearUnsub = $sentMail->unsubscribeToken;

    $response = $this->get('/newsletter/unsubscribe/'.$clearUnsub);
    $response->assertStatus(200);

    $row = SubscriptionRequest::where('email', 'ivy@example.fr')->firstOrFail();
    expect($row->status)->toBe(SubscriptionRequestStatus::Unsubscribed);
});

it('GET /newsletter/unsubscribe/{unknown-token} returns 404', function () {
    $response = $this->get('/newsletter/unsubscribe/'.str_repeat('y', 48));
    $response->assertStatus(404);
});

// ─── Task 13 : Isolation cross-tenant + CORS preflight ────────────────────────

it('cross-tenant isolation: tenant B cannot confirm a token issued for tenant A', function () {
    Mail::fake();

    // Tenant A déjà bootée par tests/Pest.php (slug "soigner-vivre-sourire")
    $service = app(SubscriptionService::class);
    $service->subscribe('jane@example.fr', 'Jane', '1.2.3.4', 'UA');

    $clearToken = collect(Mail::sent(NewsletterConfirmation::class))->first()->confirmationToken;
    $tenantAId = (int) TenantContext::currentId();

    // Crée une 2ème asso et la boote
    $assoB = Association::factory()->create(['slug' => 'autre-asso']);
    TenantContext::clear();
    TenantContext::boot($assoB);

    // Le findByConfirmationToken doit re-booter le tenant A automatiquement
    $row = $service->findByConfirmationToken($clearToken);

    expect($row)->not->toBeNull();
    expect((int) $row->association_id)->not->toBe((int) $assoB->id);
    // Et TenantContext courant doit avoir basculé vers le tenant A (celui du token)
    expect((int) TenantContext::currentId())->toBe((int) $row->association_id);
    expect((int) TenantContext::currentId())->toBe($tenantAId);
});

it('OPTIONS preflight from authorized origin returns 204 with CORS headers', function () {
    $response = $this->call('OPTIONS', '/api/newsletter/subscribe', [], [], [], [
        'HTTP_ORIGIN' => 'https://soigner-vivre-sourire.fr',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type',
    ]);

    expect($response->getStatusCode())->toBe(204);
    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->toBe('https://soigner-vivre-sourire.fr');
    expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('POST');
});
