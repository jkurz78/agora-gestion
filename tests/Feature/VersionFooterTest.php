<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Tenant\TenantContext;

// config/version.php est versionné : ces tests le suppriment ou le réécrivent pour simuler
// son absence. On garde son contenu pour le remettre après chaque test — sinon chaque
// passage de la suite supprime le fichier de la copie de travail, ou en change la date.
beforeEach(function (): void {
    $this->versionOrigine = file_exists(config_path('version.php'))
        ? file_get_contents(config_path('version.php'))
        : null;
});

afterEach(function (): void {
    TenantContext::clear();
    if ($this->versionOrigine !== null) {
        file_put_contents(config_path('version.php'), $this->versionOrigine);
    } else {
        @unlink(config_path('version.php'));
    }
});

it('AppServiceProvider::boot() génère config/version.php si le fichier est absent', function (): void {
    @unlink(config_path('version.php'));

    // Invoquer boot() directement pour simuler le démarrage de l'app sans le fichier
    $provider = new AppServiceProvider(app());
    $provider->boot();

    expect(file_exists(config_path('version.php')))->toBeTrue();

    $version = require config_path('version.php');

    expect($version)->toBeArray()
        ->toHaveKey('tag')
        ->toHaveKey('date');
});

it('AppServiceProvider::boot() ne régénère pas config/version.php si le fichier existe déjà', function (): void {
    // Écrire un fichier version factice
    file_put_contents(config_path('version.php'), "<?php\nreturn ['tag' => 'v1.0.0', 'date' => '2026-01-01'];\n");
    $mtime = filemtime(config_path('version.php'));

    // Appeler boot() — ne doit pas écraser le fichier existant
    $provider = new AppServiceProvider(app());
    $provider->boot();

    expect(filemtime(config_path('version.php')))->toBe($mtime);
});

it('le footer version est présent dans les pages authentifiées', function (): void {
    $association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);
    // Vérifier le marqueur unique du footer : "AgoraGestion &middot;" (entité HTML)
    $response->assertSee('AgoraGestion &middot;', false);
});

it('le footer version est absent des pages guest (login)', function (): void {
    $response = $this->get('/login');

    // La page login utilise guest.blade.php, pas app.blade.php
    // Elle ne doit PAS contenir "AgoraGestion &middot;" (spécifique au footer)
    $response->assertStatus(200);
    $response->assertDontSee('AgoraGestion &middot;', false);
});
