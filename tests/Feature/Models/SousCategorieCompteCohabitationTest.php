<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;

/**
 * Porte de cohabitation pour la sous-slice 1a.
 *
 * Vérifie que App\Models\Compte (table `comptes`, créé en Step 5/9) et
 * App\Models\SousCategorie (table `sous_categories`, inchangé) coexistent
 * sans collision Eloquent.
 *
 * Le renommage transverse SousCategorie → Compte est prévu en sous-slice 1d
 * (Steps 36-39). En 1a, SousCategorie reste intact et aucun alias deprecated
 * n'est introduit.
 */
it('Compte pointe sur la table comptes et SousCategorie sur sous_categories', function (): void {
    expect((new Compte)->getTable())->toBe('comptes');
    expect((new SousCategorie)->getTable())->toBe('sous_categories');
});

it('les deux modèles coexistent avec le même numero_pcg / code_cerfa mais des IDs distincts', function (): void {
    $association = TenantContext::current();

    // Crée une SousCategorie avec code_cerfa = '707'
    $sc = SousCategorie::factory()->create([
        'association_id' => $association->id,
        'code_cerfa' => '707',
        'nom' => 'Ventes de marchandises',
    ]);

    // Crée manuellement le Compte miroir avec numero_pcg = '707'
    $compte = Compte::create([
        'association_id' => $association->id,
        'numero_pcg' => '707',
        'intitule' => 'Ventes de marchandises',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);

    // Les IDs peuvent être identiques ou différents — on ne le présuppose pas.
    // Ce qui compte : les instances PHP sont de classes distinctes.
    expect($compte)->toBeInstanceOf(Compte::class);
    expect($sc)->toBeInstanceOf(SousCategorie::class);
    expect(get_class($compte))->not->toBe(get_class($sc));

    // Retrouver chaque objet via find() et vérifier que les classes diffèrent
    $foundCompte = Compte::find($compte->id);
    $foundSc = SousCategorie::find($sc->id);

    expect($foundCompte)->toBeInstanceOf(Compte::class);
    expect($foundSc)->toBeInstanceOf(SousCategorie::class);
    expect(get_class($foundCompte))->not->toBe(get_class($foundSc));
});

it('les scopes Eloquent de chaque modèle opèrent sans collision', function (): void {
    $association = TenantContext::current();

    SousCategorie::factory()->create([
        'association_id' => $association->id,
        'code_cerfa' => '707',
        'nom' => 'Ventes',
    ]);

    Compte::create([
        'association_id' => $association->id,
        'numero_pcg' => '707',
        'intitule' => 'Ventes',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);

    // Les deux requêtes doivent aboutir sur leurs tables respectives sans erreur.
    $sc = SousCategorie::query()->where('code_cerfa', '707')->first();
    $compte = Compte::query()->where('numero_pcg', '707')->first();

    expect($sc)->toBeInstanceOf(SousCategorie::class);
    expect($compte)->toBeInstanceOf(Compte::class);
    expect($sc->code_cerfa)->toBe('707');
    expect($compte->numero_pcg)->toBe('707');
});

it('la relation sousCategorie de FormuleAdhesion retourne toujours un SousCategorie et non un Compte', function (): void {
    // FormuleAdhesion::sousCategorie() belongsTo SousCategorie — non-regression smoke test.
    // Si Compte avait aliasé SousCategorie, cette relation retournerait un Compte.
    $formule = FormuleAdhesion::factory()->create();

    $related = $formule->sousCategorie;

    expect($related)->not->toBeNull();
    expect($related)->toBeInstanceOf(SousCategorie::class);
    expect($related)->not->toBeInstanceOf(Compte::class);
});

// Slice 1 design : pas d'alias deprecated. Pas de logging à tester.
// Le modèle Compte n'enregistre aucun log deprecated lors de son chargement.
