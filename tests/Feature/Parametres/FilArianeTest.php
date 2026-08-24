<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Support\Parametres\ParametresNavigation;
use App\Tenant\TenantContext;

/**
 * Fil d'Ariane des écrans de Paramètres.
 *
 * Le fil générique de `layouts/app-sidebar.blade.php` ne connaît que deux
 * niveaux — « groupe / page ». Paramètres en a désormais trois, la section
 * s'intercalant. Ce niveau est calculé depuis la taxonomie, la même source que
 * la sidebar et les droits : les trois ne peuvent donc pas se contredire.
 */
beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => RoleAssociation::Admin->value, 'joined_at' => now(),
    ]);
});

afterEach(fn () => TenantContext::clear());

/** Le contenu du fil d'Ariane seul, isolé du reste de la page. */
function filDAriane(string $html): string
{
    preg_match('#<nav aria-label="breadcrumb".*?</nav>#s', $html, $m);

    return $m[0] ?? '';
}

it('porte les trois niveaux sur un écran de paramètres', function (): void {
    $html = $this->actingAs($this->user)->get(route('parametres.adhesions.formules'))->getContent();
    $fil = filDAriane($html);

    expect($fil)->toContain('Paramètres');
    expect($fil)->toContain('Adhésions et dons');
});

it('rend « Paramètres » cliquable vers la page d’accueil', function (): void {
    // C'est le seul groupe à disposer d'une page d'accueil, donc le seul
    // cliquable dans le fil.
    $html = $this->actingAs($this->user)->get(route('parametres.plan-comptable'))->getContent();
    $fil = filDAriane($html);

    expect($fil)->toContain('href="'.route('parametres.index').'"');
});

it('le niveau section mène au premier écran de la section', function (): void {
    $html = $this->actingAs($this->user)->get(route('parametres.facturation'))->getContent();
    $fil = filDAriane($html);

    $premier = ParametresNavigation::premierEcran(
        ParametresNavigation::localiser('parametres.facturation')['section'],
        RoleAssociation::Admin,
    );

    expect($fil)->toContain('href="'.route($premier->route).'"');
});

it('le libellé de section vient de la taxonomie, pas d’une saisie locale', function (): void {
    // « Envoi d'e-mails » côté taxonomie, alors que la route s'appelle « smtp ».
    $html = $this->actingAs($this->user)->get(route('parametres.smtp'))->getContent();

    expect(filDAriane($html))->toContain('Services connectés');
});

it('n’ajoute aucun niveau hors de Paramètres', function (): void {
    $html = $this->actingAs($this->user)->get('/dashboard')->getContent();
    $fil = filDAriane($html);

    foreach (ParametresNavigation::sections() as $section) {
        expect($fil)->not->toContain($section->libelle);
    }
});
