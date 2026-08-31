<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Support\InfosTechniques;
use App\Support\Parametres\ParametresNavigation;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

function connecterInfosTechniques(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

// ── Le service ───────────────────────────────────────────────────────────────

it('collecte les quatre blocs d’informations techniques', function (): void {
    $infos = app(InfosTechniques::class)->collecter();

    expect($infos)->toHaveKeys(['agoragestion', 'socle', 'serveur', 'extensions']);
});

it('renseigne la version d’AgoraGestion et l’environnement', function (): void {
    $bloc = app(InfosTechniques::class)->collecter()['agoragestion'];

    expect($bloc['Version'])->toBe(config('version.tag'))
        ->and($bloc['Environnement'])->toBe(app()->environment());
});

it('lit les versions du socle sans les coder en dur', function (): void {
    $socle = app(InfosTechniques::class)->collecter()['socle'];

    // Les versions viennent de la plateforme et de Composer : elles restent
    // justes après chaque montée, sans intervention.
    expect($socle['PHP'])->toBe(PHP_VERSION)
        ->and($socle['Laravel'])->toBe(app()->version())
        ->and($socle['Livewire'])->not->toBe('')
        ->and($socle['Base de données'])->not->toBe('');
});

it('expose les limites du serveur utiles au diagnostic', function (): void {
    $serveur = app(InfosTechniques::class)->collecter()['serveur'];

    expect($serveur)->toHaveKeys([
        'Mémoire maximale',
        'Taille maximale d’un fichier',
        'Taille maximale d’un envoi',
        'Durée maximale d’exécution',
    ])->and($serveur['Mémoire maximale'])->toBe(ini_get('memory_limit'));
});

it('signale chaque extension critique comme présente ou absente', function (): void {
    $extensions = app(InfosTechniques::class)->collecter()['extensions'];

    expect($extensions)->toHaveKeys(['imagick', 'gd', 'intl', 'bcmath', 'zip', 'pdo_mysql'])
        ->and($extensions['bcmath'])->toBeBool()
        ->and($extensions['bcmath'])->toBe(extension_loaded('bcmath'));
});

// ── L’écran ──────────────────────────────────────────────────────────────────

it('déclare l’écran dans une section Système réservée à l’administrateur', function (): void {
    $section = collect(ParametresNavigation::sections())
        ->firstWhere('cle', 'systeme');

    expect($section)->not->toBeNull()
        ->and($section->libelle)->toBe('Système');

    $ecran = collect($section->ecrans)->firstWhere('cle', 'informations-techniques');

    expect($ecran)->not->toBeNull()
        ->and($ecran->roles)->toBe([RoleAssociation::Admin]);
});

it('affiche les blocs à un administrateur', function (): void {
    $admin = connecterInfosTechniques($this->association, RoleAssociation::Admin);

    $this->actingAs($admin)
        ->get(route('parametres.informations-techniques'))
        ->assertOk()
        ->assertSee('AgoraGestion')
        ->assertSee('Socle applicatif')
        ->assertSee('Configuration du serveur')
        ->assertSee(PHP_VERSION);
});

it('refuse l’écran à un rôle non administrateur', function (): void {
    foreach ([RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation] as $role) {
        $user = connecterInfosTechniques($this->association, $role);

        $this->actingAs($user)
            ->get(route('parametres.informations-techniques'))
            ->assertForbidden();
    }
});
