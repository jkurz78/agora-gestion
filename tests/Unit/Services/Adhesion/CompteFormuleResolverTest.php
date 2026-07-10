<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\FormuleAdhesion;
use App\Services\Adhesion\CompteFormuleResolver;
use App\Tenant\TenantContext;

/*
 * DC-10a — résolution des formules d'adhésion par compte_id uniquement
 * (le chemin legacy par sous_categorie_id a été supprimé).
 */
function compteCotisationResolverTest(string $numeroPcg): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numeroPcg,
        'intitule' => 'Cotisations '.$numeroPcg,
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);
}

it('résout la formule active du compte', function (): void {
    $compte = compteCotisationResolverTest('756A');
    $formule = FormuleAdhesion::factory()->create(['compte_id' => $compte->id, 'actif' => true]);

    $resolver = new CompteFormuleResolver;
    $result = $resolver->resolve($compte->id);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($formule->id);
});

it('retourne null si aucune formule active', function (): void {
    $compte = compteCotisationResolverTest('756B');
    FormuleAdhesion::factory()->inactif()->create(['compte_id' => $compte->id]);

    $resolver = new CompteFormuleResolver;
    $result = $resolver->resolve($compte->id);

    expect($result)->toBeNull();
});

it('retourne null si le compte n\'a aucune formule', function (): void {
    $compte = compteCotisationResolverTest('756C');

    $resolver = new CompteFormuleResolver;
    $result = $resolver->resolve($compte->id);

    expect($result)->toBeNull();
});

it('ignore les formules HelloAsso (priorité 2 = formules manuelles uniquement)', function (): void {
    $compte = compteCotisationResolverTest('756D');

    // Formule HelloAsso active sur le compte → ignorée par priorité 2
    FormuleAdhesion::factory()->create([
        'compte_id' => $compte->id,
        'actif' => true,
        'est_helloasso' => true,
        'helloasso_form_slug' => 'cotisation-2026',
        'helloasso_tier_id' => 1,
    ]);

    $resolver = new CompteFormuleResolver;
    expect($resolver->resolve($compte->id))->toBeNull();

    // Ajout d'une formule manuelle → c'est elle qui doit être retournée
    $manuelle = FormuleAdhesion::factory()->create([
        'compte_id' => $compte->id,
        'actif' => true,
        'est_helloasso' => false,
    ]);

    $resolver2 = new CompteFormuleResolver;
    expect($resolver2->resolve($compte->id)?->id)->toBe($manuelle->id);
});

it('cache le résultat sur la même instance (pas de double query)', function (): void {
    $compte = compteCotisationResolverTest('756E');
    $formule = FormuleAdhesion::factory()->create(['compte_id' => $compte->id, 'actif' => true]);

    $resolver = new CompteFormuleResolver;

    // First resolve — populates cache
    $first = $resolver->resolve($compte->id);
    expect($first)->not->toBeNull();
    expect($first->id)->toBe($formule->id);

    // Mutate DB: mark formule inactive
    $formule->update(['actif' => false]);

    // Second resolve — must return cached formule (still active in cache)
    $second = $resolver->resolve($compte->id);
    expect($second)->not->toBeNull();
    expect($second->id)->toBe($formule->id);
});

it('forget invalide l\'entrée de cache du compte', function (): void {
    $compte = compteCotisationResolverTest('756F');
    $formule = FormuleAdhesion::factory()->create(['compte_id' => $compte->id, 'actif' => true]);

    $resolver = new CompteFormuleResolver;
    expect($resolver->resolve($compte->id)?->id)->toBe($formule->id);

    $formule->update(['actif' => false]);
    $resolver->forget($compte->id);

    expect($resolver->resolve($compte->id))->toBeNull();
});
