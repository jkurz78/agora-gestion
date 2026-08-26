<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Support\Parametres\ParametresNavigation;
use App\Tenant\TenantContext;

/**
 * Sidebar : intertitres de section et dépliement contextuel.
 *
 * Le groupe Paramètres montre les QUATRE sections de ParametresNavigation,
 * jamais les douze écrans à plat. Seule la section de l'écran courant se
 * déplie — aucun repli à piloter, c'est la position qui ouvre.
 *
 * ⚠️ Ces tests visent la SIDEBAR, pas la page entière. La page d'accueil des
 * paramètres liste les douze écrans en cartes : asserter l'absence d'une URL
 * sur tout le HTML y serait impossible, le hub lie tout. D'où le marqueur de
 * classe `param-ecran-link`, porté uniquement par les écrans dépliés dans la
 * sidebar — il rend les assertions précises sans dépendre des libellés, dont
 * plusieurs entrent en collision avec d'autres menus (« Comptabilité »,
 * « Facturation », « HelloAsso » nomment aussi des groupes de premier niveau).
 */
beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(fn () => TenantContext::clear());

function connecterRoleSidebar(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

/** Les URL des écrans dépliés dans la sidebar, et elles seules. */
function ecransDepliesDansLaSidebar(string $html): array
{
    preg_match_all('#<a href="([^"]+)"\s+class="nav-link param-ecran-link#', $html, $liens);

    return $liens[1];
}

function sectionParCle(string $cle)
{
    return collect(ParametresNavigation::sections())->firstWhere('cle', $cle);
}

it('sur un écran, sa section est dépliée et elle seule', function (): void {
    $user = connecterRoleSidebar($this->association, RoleAssociation::Admin);

    $html = $this->actingAs($user)->get(route('parametres.plan-comptable'))->getContent();
    $deplies = ecransDepliesDansLaSidebar($html);

    $comptabilite = sectionParCle('comptabilite');

    expect($deplies)->toHaveCount(count($comptabilite->ecrans));

    foreach ($comptabilite->ecrans as $ecran) {
        expect($deplies)->toContain(route($ecran->route));
    }
});

it('hors de Paramètres, les intertitres sont là mais aucun écran n’est déplié', function (): void {
    $user = connecterRoleSidebar($this->association, RoleAssociation::Admin);

    $html = $this->actingAs($user)->get('/dashboard')->getContent();

    expect(ecransDepliesDansLaSidebar($html))->toBeEmpty();

    foreach (ParametresNavigation::sections() as $section) {
        $premier = ParametresNavigation::premierEcran($section, RoleAssociation::Admin);
        expect($html)->toContain(route($premier->route));
    }
});

/*
 * Les deux défauts remontés par l'exploitant à la première utilisation réelle.
 * Aucun test ne les attrapait : ils portaient tous sur ce que la sidebar
 * AFFICHE, jamais sur ce qui se passe quand on CLIQUE.
 */

it('l’en-tête « Paramètres » mène à la page d’accueil', function (): void {
    // C'était le seul en-tête de groupe à n'être qu'un bouton de repli Bootstrap :
    // cliquer « Paramètres » dépliait les intertitres sans jamais naviguer.
    $user = connecterRoleSidebar($this->association, RoleAssociation::Admin);

    $html = $this->actingAs($user)->get('/dashboard')->getContent();

    expect($html)->toContain('href="'.route('parametres.index').'"');
});

it('un intertitre mène au premier écran de sa section', function (): void {
    // Comme les huit autres groupes de la sidebar mènent à leur écran principal.
    // Maintenir la page d'accueil affichée pendant qu'on navigue dans les menus
    // désoriente — retour d'usage réel.
    $user = connecterRoleSidebar($this->association, RoleAssociation::Admin);

    $premier = ParametresNavigation::premierEcran(sectionParCle('comptabilite'), RoleAssociation::Admin);

    $html = $this->actingAs($user)->get('/dashboard')->getContent();
    expect($html)->toContain('href="'.route($premier->route).'"');

    // Et y arriver déplie la section, sans mécanisme supplémentaire : c'est la
    // position qui ouvre, la même que pour n'importe quel autre écran.
    $htmlEcran = $this->actingAs($user)->get(route($premier->route))->getContent();
    $deplies = ecransDepliesDansLaSidebar($htmlEcran);

    foreach (sectionParCle('comptabilite')->ecrans as $ecran) {
        expect($deplies)->toContain(route($ecran->route));
    }

    foreach (sectionParCle('services-connectes')->ecrans as $ecran) {
        expect($deplies)->not->toContain(route($ecran->route));
    }
});

it('le premier écran d’une section dépend du rôle', function (): void {
    // Un Gestionnaire n'a qu'un écran dans « Association et accès » : c'est
    // celui-là qui doit l'accueillir, pas celui réservé aux administrateurs.
    $section = sectionParCle('association-acces');

    $pourAdmin = ParametresNavigation::premierEcran($section, RoleAssociation::Admin);
    $pourGestionnaire = ParametresNavigation::premierEcran($section, RoleAssociation::Gestionnaire);

    expect($pourAdmin->cle)->toBe('informations');
    expect($pourGestionnaire->cle)->toBe('liens-publics');
});

it('sans section demandée, la page d’accueil ne déplie rien', function (): void {
    $user = connecterRoleSidebar($this->association, RoleAssociation::Admin);

    $html = $this->actingAs($user)->get(route('parametres.index'))->getContent();

    expect(ecransDepliesDansLaSidebar($html))->toBeEmpty();
});

it('un comptable ne voit que les sections qui lui sont ouvertes', function (): void {
    $user = connecterRoleSidebar($this->association, RoleAssociation::Comptable);

    $html = $this->actingAs($user)->get('/dashboard')->getContent();

    foreach (ParametresNavigation::sections() as $section) {
        $premier = ParametresNavigation::premierEcran($section, RoleAssociation::Comptable);

        if ($premier === null) {
            // Section sans aucun écran visible : pas d'intertitre du tout.
            expect($html)->not->toContain('#'.$section->cle);
        } else {
            expect($html)->toContain('href="'.route($premier->route).'"');
        }
    }
});

it('un rôle Consultation ne voit pas le groupe Paramètres', function (): void {
    $user = connecterRoleSidebar($this->association, RoleAssociation::Consultation);

    $html = $this->actingAs($user)->get('/dashboard')->getContent();

    expect($html)->not->toContain('grpParametres');
});
