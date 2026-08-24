<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Support\Parametres\ParametresNavigation;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(fn () => TenantContext::clear());

function connecterAvecRolePageAccueil(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

/**
 * Vérifie, écran par écran, que la page d'accueil reflète exactement la
 * matrice de rôles déclarée dans ParametresNavigation — sans retaper aucun
 * libellé.
 *
 * Deux libellés de la taxonomie entrent en collision avec du texte présent
 * ailleurs dans le gabarit (sidebar), sur TOUTES les pages et quel que soit
 * le rôle :
 *  - l'écran « Facturation » (clé comptabilite/facturation) collide avec
 *    l'intitulé du groupe de menu « Facturation » (module facturation,
 *    resources/views/components/sidebar.blade.php ligne ~435) ;
 *  - l'écran « HelloAsso » (clé services-connectes/helloasso) est un
 *    sous-texte de « Sync HelloAsso » (groupe Banques, même fichier,
 *    ligne ~332).
 * Un assertDontSee('Facturation') ou assertDontSee('HelloAsso') pour un rôle
 * qui n'a pas accès à ces écrans échouerait donc à tort : le texte est bel et
 * bien présent sur la page, mais via un lien totalement étranger à Paramètres.
 * On lève l'ambiguïté en vérifiant l'ABSENCE via l'URL de la route (unique à
 * chaque nom de route Laravel) plutôt que via le libellé pour les écrans non
 * accessibles ; la présence, elle, est toujours vérifiée par le libellé — un
 * assertSee ne peut jamais être un faux positif de collision.
 */
function assertPageAccueilRespecteMatrice(TestResponse $reponse, RoleAssociation $role): void
{
    foreach (ParametresNavigation::sections() as $section) {
        $ecransVisibles = $section->ecransVisibles($role);

        if ($ecransVisibles === []) {
            // Aucun écran accessible : la section entière (carte + intertitre)
            // doit disparaître. On vise la description, propre à cette page,
            // jamais le libellé de section — « Comptabilité » est aussi le nom
            // d'un groupe de la sidebar rendu pour tous les rôles.
            $reponse->assertDontSee($section->description);
        } else {
            $reponse->assertSee($section->description);
        }

        foreach ($section->ecrans as $ecran) {
            if ($ecran->accessiblePar($role)) {
                $reponse->assertSee($ecran->libelle);
            } else {
                $reponse->assertDontSee(route($ecran->route));
            }
        }
    }
}

it('un admin voit les quatre sections et les douze écrans', function (): void {
    $user = connecterAvecRolePageAccueil($this->association, RoleAssociation::Admin);

    $reponse = $this->actingAs($user)->get(route('parametres.index'));

    $reponse->assertOk();

    $totalSections = 0;
    $totalEcrans = 0;
    foreach (ParametresNavigation::sections() as $section) {
        $totalSections++;
        $reponse->assertSee($section->description);
        foreach ($section->ecrans as $ecran) {
            $totalEcrans++;
            $reponse->assertSee($ecran->libelle);
        }
    }

    expect($totalSections)->toBe(4);
    expect($totalEcrans)->toBe(12);

    assertPageAccueilRespecteMatrice($reponse, RoleAssociation::Admin);
});

it('un gestionnaire ne voit que ce à quoi il a droit', function (): void {
    $user = connecterAvecRolePageAccueil($this->association, RoleAssociation::Gestionnaire);

    $reponse = $this->actingAs($user)->get(route('parametres.index'));

    $reponse->assertOk();

    assertPageAccueilRespecteMatrice($reponse, RoleAssociation::Gestionnaire);
});

it('un comptable ne voit que ce à quoi il a droit', function (): void {
    $user = connecterAvecRolePageAccueil($this->association, RoleAssociation::Comptable);

    $reponse = $this->actingAs($user)->get(route('parametres.index'));

    $reponse->assertOk();

    assertPageAccueilRespecteMatrice($reponse, RoleAssociation::Comptable);
});
