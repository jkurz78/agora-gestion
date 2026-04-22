<?php

declare(strict_types=1);

use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\TiersPortailOtp;
use App\Services\Portail\OtpService;
use App\Services\Portail\RequestResult;
use App\Services\Portail\VerifyStatus;
use App\Tenant\TenantContext;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Dossier d'intrusion multi-tenant — Portail Tiers Slice 1.
 *
 * Six scénarios vérifient que l'isolation tenant du portail est étanche :
 *   1. OTP cross-asso : code émis sur A ne peut être consommé sur B.
 *   2. Email partagé   : même email dans A et B → OTP cloison par tenant.
 *   3. Session cross-asso : cookie portail A ne donne pas accès à B.
 *   4. Anti-énum cross-asso : request() sur B = Silent si Tiers inconnu de B.
 *   5. Fail-closed TiersPortailOtp : sans TenantContext → 0 lignes visibles.
 *   6. Cooldown scopé : 3 échecs sur A ne bloquent pas B.
 *
 * Ces tests sont des non-régressions de sécurité : si l'un échoue c'est une
 * faille à corriger avant tout merge.
 */

/**
 * Helper local : émet un OTP pour ($asso, $email) et retourne le code en clair.
 * Encapsulé ici pour ne pas dépendre du helper global défini dans OtpVerifyTest.
 */
function intrusion_requestAndGetCode(OtpService $service, Association $asso, string $email): string
{
    Mail::fake();
    $service->request($asso, $email);

    $code = null;
    Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

// Les tests gèrent TenantContext eux-mêmes ; on efface toujours pour être
// certain de partir d'un état propre, y compris si le beforeEach global de
// Pest.php a booté un tenant par défaut.
beforeEach(function () {
    TenantContext::clear();
    Mail::fake();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 1 : OTP émis sur asso A ne peut pas être consommé sur asso B
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] OTP émis sur asso A retourne Invalid quand verify est appelé sur asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    // Tiers marie dans asso A uniquement
    TenantContext::boot($assoA);
    Tiers::factory()->create([
        'association_id' => $assoA->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);

    // Nettoyer le RateLimiter pour éviter toute pollution cross-test
    RateLimiter::clear('portail-otp:'.$assoA->id.':marie@example.org');
    RateLimiter::clear('portail-otp:'.$assoB->id.':marie@example.org');

    // Émettre un OTP sur asso A (TenantContext est booté sur A)
    $code = intrusion_requestAndGetCode($service, $assoA, 'marie@example.org');

    // Tenter de consommer ce code sur asso B : TenantScope filtre sur assoB
    // → aucun OTP visible pour assoB → VerifyStatus::Invalid
    TenantContext::boot($assoB);
    $result = $service->verify($assoB, 'marie@example.org', $code);

    expect($result->status)->toBe(VerifyStatus::Invalid);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 2 : même email Tiers dans deux associations → OTP cloisonné
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] email partagé dans deux assos — OTP asso A invalide sur asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    // marie@example.org existe dans asso A
    TenantContext::boot($assoA);
    Tiers::factory()->create([
        'association_id' => $assoA->id,
        'email' => 'marie@example.org',
    ]);

    // marie@example.org existe aussi dans asso B
    TenantContext::boot($assoB);
    Tiers::factory()->create([
        'association_id' => $assoB->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);

    RateLimiter::clear('portail-otp:'.$assoA->id.':marie@example.org');
    RateLimiter::clear('portail-otp:'.$assoB->id.':marie@example.org');

    // OTP généré pour asso A — le record TiersPortailOtp a association_id = assoA
    TenantContext::boot($assoA);
    $code = intrusion_requestAndGetCode($service, $assoA, 'marie@example.org');

    // Verify sur asso B : TenantScope appliqué sur assoB ne voit pas le record assoA
    TenantContext::boot($assoB);
    $result = $service->verify($assoB, 'marie@example.org', $code);

    // L'OTP d'assoA est invisible depuis assoB → Invalid
    expect($result->status)->toBe(VerifyStatus::Invalid);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 3 : session portail asso A ne donne pas accès à portail asso B
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] session authentifiée sur asso A redirige vers login de asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    // Créer tiersA dans asso A uniquement
    TenantContext::boot($assoA);
    $tiersA = Tiers::factory()->create(['association_id' => $assoA->id]);

    // Simuler la session d'un utilisateur authentifié sur asso A en écrivant
    // directement la clé de session que le SessionGuard utilise.
    // Cela reproduit fidèlement le scénario "cross-request" : l'attaquant
    // possède un cookie de session asso A et adresse une requête à asso B.
    // On ne passe PAS par login() (qui cacherait l'objet Tiers en mémoire)
    // pour forcer le guard à re-faire Tiers::find() dans le contexte de assoB.
    $sessionKey = 'login_tiers-portail_'.sha1(SessionGuard::class);

    $this->withSession([
        $sessionKey => $tiersA->id,
        'portail.last_activity_at' => now()->timestamp,
    ])
        ->get("/{$assoB->slug}/portail/")
        ->assertRedirect("/{$assoB->slug}/portail/login");
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 4 : Tiers dans asso A seulement — request() sur asso B = Silent
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] request() sur asso B retourne Silent si Tiers inconnu de B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    // Tiers marie uniquement dans asso A
    TenantContext::boot($assoA);
    Tiers::factory()->create([
        'association_id' => $assoA->id,
        'email' => 'marie@example.org',
    ]);

    RateLimiter::clear('portail-otp:'.$assoB->id.':marie@example.org');

    // Boote asso B et demande un OTP : Tiers n'existe pas dans B
    TenantContext::boot($assoB);
    $result = app(OtpService::class)->request($assoB, 'marie@example.org');

    // Anti-énumération : réponse identique à un email inconnu
    expect($result)->toBe(RequestResult::Silent);

    // Aucun OTP créé, aucun mail envoyé
    expect(TiersPortailOtp::withoutGlobalScopes()->count())->toBe(0);
    Mail::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 5 : TenantScope fail-closed sur TiersPortailOtp
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] TenantScope fail-closed sur TiersPortailOtp — 0 lignes sans contexte', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    // Créer un OTP pour asso A directement en base
    TenantContext::boot($assoA);
    Tiers::factory()->create([
        'association_id' => $assoA->id,
        'email' => 'marie@example.org',
    ]);
    TiersPortailOtp::create([
        'association_id' => $assoA->id,
        'email' => 'marie@example.org',
        'code_hash' => 'hash_test',
        'expires_at' => now()->addMinutes(10),
        'last_sent_at' => now(),
        'attempts' => 0,
    ]);

    // Sans TenantContext (fail-closed) → 0 lignes visibles
    TenantContext::clear();
    expect(TiersPortailOtp::count())->toBe(0);

    // Avec asso B booté → toujours 0 (le record appartient à assoA)
    TenantContext::boot($assoB);
    expect(TiersPortailOtp::count())->toBe(0);

    // Avec asso A booté → le record est visible
    TenantContext::boot($assoA);
    expect(TiersPortailOtp::count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 6 : cooldown scopé par (asso, email) — 3 échecs sur A ne bloquent pas B
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] cooldown sur asso A ne bloque pas verify sur asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    // Tiers marie dans les deux assos
    TenantContext::boot($assoA);
    Tiers::factory()->create([
        'association_id' => $assoA->id,
        'email' => 'marie@example.org',
    ]);

    TenantContext::boot($assoB);
    Tiers::factory()->create([
        'association_id' => $assoB->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);

    // Nettoyer les clés pour repartir propre
    RateLimiter::clear('portail-otp:'.$assoA->id.':marie@example.org');
    RateLimiter::clear('portail-otp:'.$assoB->id.':marie@example.org');

    // Déclencher le cooldown sur asso A : 3 mauvais codes
    TenantContext::boot($assoA);
    intrusion_requestAndGetCode($service, $assoA, 'marie@example.org');
    $service->verify($assoA, 'marie@example.org', '00000001');
    $service->verify($assoA, 'marie@example.org', '00000002');
    $service->verify($assoA, 'marie@example.org', '00000003');

    // Cooldown actif sur asso A
    expect($service->cooldownActive($assoA, 'marie@example.org'))->toBeTrue();

    // Sur asso B, aucun cooldown : la clé RateLimiter inclut l'asso_id
    TenantContext::boot($assoB);
    expect($service->cooldownActive($assoB, 'marie@example.org'))->toBeFalse();

    // verify() sur asso B retourne Invalid (pas d'OTP) mais PAS Cooldown
    $result = $service->verify($assoB, 'marie@example.org', '12345678');
    expect($result->status)->toBe(VerifyStatus::Invalid);
});
