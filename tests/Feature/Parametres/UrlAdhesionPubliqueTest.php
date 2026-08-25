<?php

declare(strict_types=1);

use App\Livewire\Exercices\ChangerExercice;
use App\Models\Association;
use App\Models\Exercice;
use App\Tenant\TenantContext;

/**
 * L'URL d'adhésion est unique, portée par l'association.
 *
 * Deux champs décrivaient la même réalité : `Association.url_renouvellement_adhesion`
 * (lu par le portail membre) et `Exercice.helloasso_url` (lu par le formulaire
 * public). Le rattachement par exercice est inutilement complexe — on ne revient
 * jamais en arrière sur une URL d'adhésion — et il INTERDIT le cas utile : pointer
 * dès août vers la saison suivante, pour offrir un mois plutôt que vendre une
 * adhésion presque périmée.
 */
beforeEach(function (): void {
    $this->association = Association::factory()->create([
        'url_renouvellement_adhesion' => 'https://helloasso.com/adhesion-association',
        'url_site_web' => 'https://exemple.org',
    ]);
    TenantContext::boot($this->association);
});

afterEach(fn () => TenantContext::clear());

it('sert l’URL de l’association, pas celle de l’exercice', function (): void {
    // Exercice n'a pas de factory : insertion directe, comme ailleurs dans la suite.
    Exercice::create([
        'association_id' => TenantContext::currentId(),
        'annee' => 2025,
        'statut' => 'ouvert',
        'helloasso_url' => 'https://helloasso.com/adhesion-exercice',
    ]);

    expect($this->association->urlRenouvellementAdhesion())
        ->toBe('https://helloasso.com/adhesion-association');
});

it('se replie sur le site web quand aucune URL d’adhésion n’est renseignée', function (): void {
    $this->association->update(['url_renouvellement_adhesion' => null]);

    expect($this->association->fresh()->urlRenouvellementAdhesion())
        ->toBe('https://exemple.org');
});

it('l’écran Exercice n’édite plus d’URL HelloAsso', function (): void {
    // La colonne reste en base — sa suppression sera un nettoyage séparé — mais
    // plus aucun écran ne la pilote : une seule URL, un seul endroit.
    $composant = new ReflectionClass(ChangerExercice::class);

    expect($composant->hasProperty('editHelloassoUrl'))->toBeFalse();
});
