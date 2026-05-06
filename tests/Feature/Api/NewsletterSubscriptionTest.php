<?php

declare(strict_types=1);

use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Mail\NewsletterConfirmation;
use App\Models\Association;
use App\Models\Association\ApiKey;
use App\Models\Newsletter\SubscriptionRequest;
use App\Services\Newsletter\SubscriptionService;
use App\Tenant\TenantContext;
use App\Tenant\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

// ─── Helper : signe une requête newsletter HMAC-SHA256 ───────────────────────

function signNewsletterRequest(array $payload, ApiKey $apiKey, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $signature = 'v1='.hash_hmac(
        'sha256',
        $timestamp.'.'.$body,
        (string) $apiKey->secret_encrypted
    );

    return [
        'headers' => [
            'X-Key-Id' => $apiKey->key_id,
            'X-Timestamp' => (string) $timestamp,
            'X-Signature' => $signature,
            'Content-Type' => 'application/json',
        ],
        'body' => $body,
    ];
}

// ─── Task R1 : Schema association_api_keys ────────────────────────────────────

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

it('creates the association_api_keys table with all expected columns', function () {
    expect(Schema::hasTable('association_api_keys'))->toBeTrue();
    expect(Schema::hasColumns('association_api_keys', [
        'id',
        'association_id',
        'key_id',
        'secret_encrypted',
        'label',
        'scopes',
        'last_used_at',
        'revoked_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

// ─── Task R2 : Modèle ApiKey ──────────────────────────────────────────────────

it('creates an ApiKey via factory belonging to the current tenant association', function () {
    $apiKey = ApiKey::factory()->create();

    expect($apiKey->key_id)->toStartWith('ak_');
    expect(strlen($apiKey->key_id))->toBe(35);  // "ak_" + 32 hex
    expect($apiKey->secret_encrypted)->toBeString();
    expect(strlen($apiKey->secret_encrypted))->toBe(64);  // 32 bytes hex
    expect($apiKey->revoked_at)->toBeNull();
});

it('scope active() filters out revoked keys', function () {
    ApiKey::factory()->create();
    $revoked = ApiKey::factory()->create(['revoked_at' => now()]);

    expect(ApiKey::active()->count())->toBe(1);
    expect(ApiKey::active()->where('id', $revoked->id)->exists())->toBeFalse();
});

it('findByKeyId returns the active key, null if revoked or unknown', function () {
    $active = ApiKey::factory()->create();
    $revoked = ApiKey::factory()->create(['revoked_at' => now()]);

    expect(ApiKey::findByKeyId($active->key_id)?->id)->toBe($active->id);
    expect(ApiKey::findByKeyId($revoked->key_id))->toBeNull();
    expect(ApiKey::findByKeyId('ak_unknown'))->toBeNull();
});

it('secret is encrypted at rest (DB raw value differs from accessor)', function () {
    $apiKey = ApiKey::factory()->create();

    $rawDb = DB::table('association_api_keys')
        ->where('id', $apiKey->id)
        ->value('secret_encrypted');

    expect($rawDb)->not->toBe($apiKey->secret_encrypted);  // chiffré ≠ clair
    expect(strlen($rawDb))->toBeGreaterThan(64);            // overhead AES + base64
});

// ─── Tasks model : SubscriptionRequest ───────────────────────────────────────

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

// ─── Task 13 : Isolation cross-tenant ────────────────────────────────────────

it('cross-tenant isolation: tenant B cannot confirm a token issued for tenant A', function () {
    Mail::fake();

    // Tenant A déjà bootée par tests/Pest.php
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

// ─── Task 14 : Commande newsletter:forget ────────────────────────────────────

it('newsletter:forget {email} hard-deletes all rows for that email', function () {
    SubscriptionRequest::factory()->create(['email' => 'kate@example.fr', 'status' => SubscriptionRequestStatus::Pending]);
    SubscriptionRequest::factory()->create(['email' => 'kate@example.fr', 'status' => SubscriptionRequestStatus::Confirmed]);
    SubscriptionRequest::factory()->create(['email' => 'kate@example.fr', 'status' => SubscriptionRequestStatus::Unsubscribed]);
    SubscriptionRequest::factory()->create(['email' => 'other@example.fr']);

    $exitCode = Artisan::call('newsletter:forget', ['email' => 'kate@example.fr']);

    expect($exitCode)->toBe(0);

    // Désactive le scope tenant pour vérifier la suppression globale
    $remaining = SubscriptionRequest::withoutGlobalScope(TenantScope::class)
        ->where('email', 'kate@example.fr')
        ->count();
    expect($remaining)->toBe(0);

    expect(SubscriptionRequest::where('email', 'other@example.fr')->count())->toBe(1);
});

// ─── Task R6 : Commande newsletter:keys:create ───────────────────────────────

it('newsletter:keys:create generates a key, stores encrypted secret, displays clear secret once', function () {
    $association = TenantContext::current();

    $exitCode = Artisan::call('newsletter:keys:create', [
        '--association' => $association->id,
        '--label' => 'Test key from command',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('KEY_ID');
    expect($output)->toContain('SECRET');
    expect($output)->toContain('ak_');

    // Une clé existe en DB pour cette asso (le plus récent qui porte ce label)
    $apiKey = ApiKey::where('association_id', $association->id)
        ->where('label', 'Test key from command')
        ->latest()
        ->first();
    expect($apiKey)->not->toBeNull();
    expect($apiKey->label)->toBe('Test key from command');

    // Le KEY_ID affiché doit matcher celui en DB
    preg_match('/KEY_ID\s*:\s*(ak_[a-f0-9]+)/', $output, $m);
    expect($apiKey->key_id)->toBe($m[1]);
});

it('newsletter:keys:create fails (1) when association not found', function () {
    $exitCode = Artisan::call('newsletter:keys:create', [
        '--association' => 999999,
    ]);
    expect($exitCode)->not->toBe(0);
});

// ─── Tasks 9–11 + R5 : Middleware HMAC + Controller HTTP ─────────────────────
// (Ces tests nécessitent une ApiKey ; le describe scope le beforeEach.)

describe('HMAC middleware + HTTP controller', function () {
    beforeEach(function () {
        /** @var Association $association */
        $association = TenantContext::current();
        $this->apiKey = ApiKey::factory()->for($association)->create([
            'label' => 'Test key',
        ]);
    });

    it('POST /api/newsletter/subscribe with valid payload returns 200 and creates a pending row', function () {
        Mail::fake();

        $payload = [
            'email' => 'alice@example.fr',
            'prenom' => 'Alice',
            'consent' => true,
            'bot_trap' => '',
        ];
        $signed = signNewsletterRequest($payload, $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(200)->assertJson(['status' => 'pending_double_optin']);

        $row = SubscriptionRequest::where('email', 'alice@example.fr')->first();
        expect($row)->not->toBeNull();
        expect($row->status)->toBe(SubscriptionRequestStatus::Pending);
        expect((int) $row->association_id)->toBe((int) TenantContext::currentId());

        Mail::assertSent(NewsletterConfirmation::class);
    });

    it('POST with malformed email returns 422 with validation_failed shape', function () {
        $payload = [
            'email' => 'pas-un-email',
            'consent' => true,
            'bot_trap' => '',
        ];
        $signed = signNewsletterRequest($payload, $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(422)
            ->assertJsonStructure(['error', 'fields' => ['email']])
            ->assertJson(['error' => 'validation_failed']);
    });

    it('POST without consent returns 422', function () {
        $payload = [
            'email' => 'alice@example.fr',
            'consent' => false,
            'bot_trap' => '',
        ];
        $signed = signNewsletterRequest($payload, $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['fields' => ['consent']]);
    });

    it('POST with filled bot_trap returns 200 silently with no row and no mail', function () {
        Mail::fake();

        $payload = [
            'email' => 'bot@spam.com',
            'consent' => true,
            'bot_trap' => 'http://link-spam',
        ];
        $signed = signNewsletterRequest($payload, $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(200)->assertJson(['status' => 'pending_double_optin']);
        expect(SubscriptionRequest::count())->toBe(0);
        Mail::assertNothingSent();
    });

    it('rate-limits to 5 requests per IP per hour', function () {
        Mail::fake();

        for ($i = 1; $i <= 5; $i++) {
            $payload = [
                'email' => "user{$i}@example.fr",
                'consent' => true,
                'bot_trap' => '',
            ];
            $signed = signNewsletterRequest($payload, $this->apiKey);

            $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
                'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
                'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            ], $signed['body'])->assertStatus(200);
        }

        $payload6 = [
            'email' => 'user6@example.fr',
            'consent' => true,
            'bot_trap' => '',
        ];
        $signed6 = signNewsletterRequest($payload6, $this->apiKey);

        $sixth = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed6['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed6['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed6['headers']['X-Signature'],
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ], $signed6['body']);

        $sixth->assertStatus(429)->assertJson(['error' => 'rate_limit']);
    });

    // ─── Task 15 : Pas de PII dans les logs ──────────────────────────────────

    it('does not log PII (email, IP) on subscribe', function () {
        Mail::fake();

        $logSpy = Log::spy();

        $payload = [
            'email' => 'larry@example.fr',
            'consent' => true,
            'bot_trap' => '',
        ];
        $signed = signNewsletterRequest($payload, $this->apiKey);

        $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body'])->assertStatus(200);

        // Inspecte tous les appels au logger préfixés newsletter.* :
        // aucun ne doit contenir l'email ni l'IP locale.
        $logSpy->shouldNotHaveReceived('info', function (string $message, array $context = []) {
            if (! str_starts_with($message, 'newsletter.')) {
                return false;
            }

            $haystack = $message.' '.json_encode($context);

            return str_contains($haystack, 'larry@example.fr')
                || str_contains($haystack, '127.0.0.1');
        });
    });

    // ─── Task R5 : Nouveaux tests HMAC failure-modes ─────────────────────────

    it('rejects POST without X-Signature header (403)', function () {
        Mail::fake();
        $signed = signNewsletterRequest(['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''], $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            // X-Signature manquant
        ], $signed['body']);

        $response->assertStatus(403);
        expect(SubscriptionRequest::count())->toBe(0);
    });

    it('rejects POST without X-Key-Id (403)', function () {
        $signed = signNewsletterRequest(['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''], $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(403);
    });

    it('rejects POST without X-Timestamp (403)', function () {
        $signed = signNewsletterRequest(['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''], $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(403);
    });

    it('rejects POST with forged signature (403)', function () {
        $signed = signNewsletterRequest(['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''], $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => 'v1='.str_repeat('0', 64),
        ], $signed['body']);

        $response->assertStatus(403);
    });

    it('rejects POST with unknown X-Key-Id (403)', function () {
        $signed = signNewsletterRequest(['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''], $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => 'ak_unknown_key_id_no_match',
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(403);
    });

    it('rejects POST with revoked key (403)', function () {
        $this->apiKey->revoke();
        $signed = signNewsletterRequest(['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''], $this->apiKey);

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(403);
    });

    it('rejects POST with stale timestamp (more than 5 minutes ago)', function () {
        $stale = time() - 600;  // -10 min
        $signed = signNewsletterRequest(
            ['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''],
            $this->apiKey,
            $stale
        );

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(403);
    });

    it('rejects POST with future timestamp (more than 5 minutes ahead)', function () {
        $future = time() + 600;
        $signed = signNewsletterRequest(
            ['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''],
            $this->apiKey,
            $future
        );

        $response = $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body']);

        $response->assertStatus(403);
    });

    it('valid POST updates last_used_at on the api key', function () {
        Mail::fake();
        expect($this->apiKey->fresh()->last_used_at)->toBeNull();

        $signed = signNewsletterRequest(['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''], $this->apiKey);

        $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body'])->assertStatus(200);

        expect($this->apiKey->fresh()->last_used_at)->not->toBeNull();
    });

    it('cross-tenant: a key for asso A boots tenant A even if context was B', function () {
        Mail::fake();

        // Crée tenant B et boote-le
        $assoB = Association::factory()->create();
        TenantContext::clear();
        TenantContext::boot($assoB);

        // La clé $this->apiKey appartient à l'asso d'origine (avant ce test) —
        // on la recharge pour s'assurer qu'elle existe toujours
        $apiKeyForA = ApiKey::find($this->apiKey->id);
        expect($apiKeyForA)->not->toBeNull();
        expect((int) $apiKeyForA->association_id)->not->toBe((int) $assoB->id);

        $signed = signNewsletterRequest(
            ['email' => 'a@b.fr', 'consent' => true, 'bot_trap' => ''],
            $apiKeyForA
        );

        $this->call('POST', '/api/newsletter/subscribe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KEY_ID' => $signed['headers']['X-Key-Id'],
            'HTTP_X_TIMESTAMP' => $signed['headers']['X-Timestamp'],
            'HTTP_X_SIGNATURE' => $signed['headers']['X-Signature'],
        ], $signed['body'])->assertStatus(200);

        // La ligne créée appartient à l'asso A, pas B
        $row = SubscriptionRequest::withoutGlobalScope(TenantScope::class)
            ->where('email', 'a@b.fr')->first();
        expect((int) $row->association_id)->toBe((int) $apiKeyForA->association_id);
    });
});
