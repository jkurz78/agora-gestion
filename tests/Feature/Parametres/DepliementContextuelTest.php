<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Support\Parametres\EcranParametre;
use App\Support\Parametres\ParametresNavigation;
use App\Support\Parametres\SectionParametres;
use App\Tenant\TenantContext;

/**
 * Sidebar : intertitres de section + dépliement contextuel (Task 12).
 *
 * Le groupe Paramètres de la sidebar montre les QUATRE sections de
 * ParametresNavigation, jamais les douze écrans à plat. Seule la section de
 * l'écran courant se déplie — aucun repli à piloter, c'est la position qui
 * ouvre. Chaque intertitre mène à son ancre sur la page d'accueil.
 *
 * 🪤 Piège des assertions d'absence — QUATRIÈME collision, nouvelle celle-ci :
 * `resources/views/layouts/app.blade.php` (lignes ~523-578) porte un vieux
 * dropdown de navbar "Paramètres", totalement indépendant de la sidebar et
 * NON gardé par rôle, qui pointe en dur vers cinq routes :
 * parametres.association, parametres.helloasso, parametres.reception-documents,
 * parametres.plan-comptable, parametres.utilisateurs.index. Ces cinq URLs sont
 * donc présentes sur CHAQUE page authentifiée, quels que soient le rôle et la
 * position — un assertDontSee(route(...)) dessus échouerait à tort. On limite
 * donc les assertions d'ABSENCE d'écran aux sept écrans non touchés par ce
 * dropdown ; les trois collisions de libellé déjà connues (Comptabilité,
 * Facturation, HelloAsso) restent hors de portée des assertSee, comme
 * toujours. La présence, elle, n'est jamais affectée par cette pollution et
 * peut viser n'importe quel écran ou libellé.
 */
const ECRANS_NON_POLLUES_PAR_NAVBAR = [
    'liens-publics',
    'formules-adhesion',
    'recus-fiscaux',
    'affectations-comptables',
    'facturation',
    'envoi-emails',
    'ocr-ia',
];

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(fn () => TenantContext::clear());

function connecterAvecRolePourDepliement(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

/** @return array{section: SectionParametres, ecran: EcranParametre} */
function trouverEcranNonPollue(string $sectionCle): array
{
    foreach (ParametresNavigation::sections() as $section) {
        if ($section->cle !== $sectionCle) {
            continue;
        }
        foreach ($section->ecrans as $ecran) {
            if (in_array($ecran->cle, ECRANS_NON_POLLUES_PAR_NAVBAR, true)) {
                return ['section' => $section, 'ecran' => $ecran];
            }
        }
    }

    throw new RuntimeException("Aucun écran non pollué trouvé pour la section {$sectionCle}.");
}

it('sur un écran de Paramètres, sa section est dépliée et elle seule', function (): void {
    $user = connecterAvecRolePourDepliement($this->association, RoleAssociation::Admin);

    // Position : comptabilite, via un écran non pollué par le dropdown navbar.
    $courant = trouverEcranNonPollue('comptabilite');

    $reponse = $this->actingAs($user)->get(route($courant['ecran']->route));
    $reponse->assertOk();

    foreach (ParametresNavigation::sections() as $section) {
        // Les quatre intertitres restent visibles quelle que soit la position.
        $reponse->assertSee(route('parametres.index').'#'.$section->cle, false);

        if ($section->cle === $courant['section']->cle) {
            // Section courante : tous ses écrans (voisins compris) sont dépliés.
            foreach ($section->ecransVisibles(RoleAssociation::Admin) as $ecran) {
                $reponse->assertSee(route($ecran->route), false);
            }

            continue;
        }

        // Section voisine, non courante : ses écrans ne sont PAS dépliés — on
        // ne vérifie que les écrans non pollués par le dropdown navbar legacy.
        foreach ($section->ecrans as $ecran) {
            if (! in_array($ecran->cle, ECRANS_NON_POLLUES_PAR_NAVBAR, true)) {
                continue;
            }
            $reponse->assertDontSee(route($ecran->route));
        }
    }
});

it('hors de Paramètres, les quatre intertitres sont là mais aucun écran n’est déplié', function (): void {
    $user = connecterAvecRolePourDepliement($this->association, RoleAssociation::Admin);

    $reponse = $this->actingAs($user)->get(route('dashboard'));
    $reponse->assertOk();

    foreach (ParametresNavigation::sections() as $section) {
        $reponse->assertSee(route('parametres.index').'#'.$section->cle, false);

        foreach ($section->ecrans as $ecran) {
            if (! in_array($ecran->cle, ECRANS_NON_POLLUES_PAR_NAVBAR, true)) {
                continue;
            }
            $reponse->assertDontSee(route($ecran->route));
        }
    }
});

it('un comptable voit le groupe Paramètres et seulement les sections qui lui sont ouvertes', function (): void {
    $user = connecterAvecRolePourDepliement($this->association, RoleAssociation::Comptable);

    // recus-fiscaux (adhesions-dons) : seul écran de sa section accessible à
    // un comptable, et non pollué par le dropdown navbar.
    $courant = ParametresNavigation::localiser('parametres.recus-fiscaux');
    expect($courant)->not->toBeNull();
    expect($courant['ecran']->accessiblePar(RoleAssociation::Comptable))->toBeTrue();

    $reponse = $this->actingAs($user)->get(route($courant['ecran']->route));
    $reponse->assertOk();

    foreach (ParametresNavigation::sections() as $section) {
        $ancre = route('parametres.index').'#'.$section->cle;
        $ecransComptable = $section->ecransVisibles(RoleAssociation::Comptable);

        if ($ecransComptable === []) {
            // association-acces et services-connectes : entièrement fermés au
            // comptable (aucun de leurs écrans ne lui est ouvert) → l'intertitre
            // lui-même disparaît, comme sur la page d'accueil (Task 11).
            $reponse->assertDontSee($ancre, false);

            continue;
        }

        $reponse->assertSee($ancre, false);

        if ($section->cle === $courant['section']->cle) {
            $reponse->assertSee(route($courant['ecran']->route), false);

            continue;
        }

        // comptabilite : ouverte au comptable mais pas la section courante —
        // son intertitre est là, ses écrans ne sont pas dépliés.
        foreach ($ecransComptable as $ecran) {
            if (! in_array($ecran->cle, ECRANS_NON_POLLUES_PAR_NAVBAR, true)) {
                continue;
            }
            $reponse->assertDontSee(route($ecran->route));
        }
    }
});

it('un rôle Consultation ne voit pas le groupe Paramètres du tout', function (): void {
    $user = connecterAvecRolePourDepliement($this->association, RoleAssociation::Consultation);

    $reponse = $this->actingAs($user)->get(route('dashboard'));
    $reponse->assertOk();

    // Marqueur structurel de l'accordéon Paramètres — unique par construction,
    // indépendant de tout libellé.
    $reponse->assertDontSee('id="grpParametres"', false);

    // Confirmation indépendante : aucune ancre de section n'est atteignable.
    foreach (ParametresNavigation::sections() as $section) {
        $reponse->assertDontSee(route('parametres.index').'#'.$section->cle, false);
    }
});
