<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Categorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    // Global Pest.php bootstrap crée déjà une Association et booote TenantContext.
    // On crée un user admin rattaché à cette asso pour pouvoir s'authentifier.
    $association = TenantContext::current();

    $this->adminUser = User::factory()->create();
    $this->adminUser->associations()->attach($association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->adminUser->update(['derniere_association_id' => $association->id]);

    // Note: session(['current_association_id' => ...]) seule ne suffit pas dans les tests
    // qui changent l'environment — le ResolveTenant middleware lira la session via withSession().
});

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
});

// ─── Helper : construire une requête authentifiée avec session tenant ───

function demoRequest(mixed $test): mixed
{
    $association = TenantContext::current();

    return $test->actingAs($test->adminUser)
        ->withSession(['current_association_id' => $association->id])
        ->withoutMiddleware(ValidateCsrfToken::class);
}

// ─── Test 1 : env=demo → POST/PUT/DELETE sur routes protégées → 403 + log ───

it('blocks POST on parametres/utilisateurs in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');
    Log::spy();

    demoRequest($this)
        ->post('/parametres/utilisateurs', [])
        ->assertStatus(403);

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message): bool {
        return $message === 'demo.write_blocked';
    });
});

it('blocks DELETE on parametres/utilisateurs in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');
    Log::spy();

    $victim = User::factory()->create();

    demoRequest($this)
        ->delete("/parametres/utilisateurs/{$victim->id}")
        ->assertStatus(403);

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message): bool {
        return $message === 'demo.write_blocked';
    });
});

it('blocks POST on parametres/categories in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');
    Log::spy();

    demoRequest($this)
        ->post('/parametres/categories', [])
        ->assertStatus(403);

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message): bool {
        return $message === 'demo.write_blocked';
    });
});

it('blocks PUT on parametres/categories in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');
    Log::spy();

    $categorie = Categorie::factory()->create([
        'association_id' => TenantContext::current()->id,
    ]);

    demoRequest($this)
        ->put("/parametres/categories/{$categorie->id}", [])
        ->assertStatus(403);

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message): bool {
        return $message === 'demo.write_blocked';
    });
});

// ─── Test 2 : env=demo → GET sur ces routes → pas de 403 du middleware ───

it('allows GET on parametres/utilisateurs in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    demoRequest($this)
        ->get('/parametres/utilisateurs')
        ->assertStatus(200);
});

it('allows GET on parametres/smtp in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    demoRequest($this)
        ->get('/parametres/smtp')
        ->assertStatus(200);
});

it('allows GET on parametres/helloasso in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    demoRequest($this)
        ->get('/parametres/helloasso')
        ->assertStatus(200);
});

// ─── Test 3 : env=local → POST sur routes protégées → pas de 403 middleware démo ───

it('does not block POST on parametres/utilisateurs in local env', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    // On envoie une requête POST intentionnellement invalide (validation échouera → 422 ou redirect),
    // mais surtout elle ne doit PAS retourner 403 à cause du middleware démo.
    $response = demoRequest($this)
        ->post('/parametres/utilisateurs', []);

    // 403 serait le middleware démo — on vérifie qu'il n'intervient pas.
    // 422 (validation) ou 302 (redirect back) sont acceptables : le middleware a laissé passer.
    expect($response->status())->not->toBe(403);
});

it('does not block DELETE on parametres/utilisateurs in local env', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $victim = User::factory()->create();

    $response = demoRequest($this)
        ->delete("/parametres/utilisateurs/{$victim->id}");

    // Pas de 403 middleware démo (peut être 302 redirect ou autre)
    expect($response->status())->not->toBe(403);
});
