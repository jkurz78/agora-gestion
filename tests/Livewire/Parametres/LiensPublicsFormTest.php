<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Parametres\LiensPublicsForm;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Premier des cinq déplacements qui vident AssociationForm (cf.
 * tests/Feature/Parametres/DecoupeAssociationFormTest.php) : les trois URL
 * publiques (site, adhésion, don) quittent l'onglet Informations pour cet
 * écran dédié, ouvert au Gestionnaire en plus de l'Admin.
 */
function connecterAvecRolePourLiensPublics(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

it('charge les trois URL de l\'association au montage', function (): void {
    $association = Association::factory()->create([
        'url_site_web' => 'https://monasso.fr',
        'url_renouvellement_adhesion' => 'https://helloasso.com/monasso/adhesion-2026',
        'url_nouveau_don' => 'https://helloasso.com/monasso/don',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourLiensPublics($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(LiensPublicsForm::class)
        ->assertSet('url_site_web', 'https://monasso.fr')
        ->assertSet('url_renouvellement_adhesion', 'https://helloasso.com/monasso/adhesion-2026')
        ->assertSet('url_nouveau_don', 'https://helloasso.com/monasso/don');
});

it('enregistre les trois URL', function (): void {
    $association = Association::factory()->create([
        'url_site_web' => null,
        'url_renouvellement_adhesion' => null,
        'url_nouveau_don' => null,
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourLiensPublics($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(LiensPublicsForm::class)
        ->set('url_site_web', 'https://monasso.fr')
        ->set('url_renouvellement_adhesion', 'https://helloasso.com/monasso/adhesion-2026')
        ->set('url_nouveau_don', 'https://helloasso.com/monasso/don')
        ->call('save')
        ->assertHasNoErrors();

    $association->refresh();
    expect($association->url_site_web)->toBe('https://monasso.fr');
    expect($association->url_renouvellement_adhesion)->toBe('https://helloasso.com/monasso/adhesion-2026');
    expect($association->url_nouveau_don)->toBe('https://helloasso.com/monasso/don');
});

it('refuse une URL mal formée', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourLiensPublics($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(LiensPublicsForm::class)
        ->set('url_site_web', 'pas-une-url')
        ->call('save')
        ->assertHasErrors(['url_site_web' => 'url']);
});

it('enregistre un champ vidé à null, pas à chaîne vide', function (): void {
    $association = Association::factory()->create([
        'url_nouveau_don' => 'https://helloasso.com/monasso/don',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourLiensPublics($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(LiensPublicsForm::class)
        ->set('url_nouveau_don', '')
        ->call('save')
        ->assertHasNoErrors();

    $association->refresh();
    expect($association->url_nouveau_don)->toBeNull();
});

it('un gestionnaire monte LiensPublicsForm sans erreur', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourLiensPublics($association, RoleAssociation::Gestionnaire);

    Livewire::actingAs($user)->test(LiensPublicsForm::class)->assertOk();
});

it('un comptable est refusé au montage de LiensPublicsForm', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourLiensPublics($association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(LiensPublicsForm::class)->assertForbidden();
});
