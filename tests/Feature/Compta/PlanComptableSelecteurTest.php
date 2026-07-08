<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\Famille;
use App\Services\Compta\PlanComptableSelecteur;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * DC-8 — dissolution sous_categories → comptes.
 *
 * `PlanComptableSelecteur::groupesPourType()` est la source de données
 * PARTAGÉE que tout écran de sélection de compte de ventilation (dépense ou
 * recette) consommera (Deliverable 3). Elle reprend le regroupement par
 * famille déjà éprouvé par l'écran Plan comptable (PlanComptable::render()).
 */
function creerCompte(int $associationId, string $numeroPcg, int $classe, bool $actif = true): Compte
{
    return Compte::forceCreate([
        'association_id' => $associationId,
        'numero_pcg' => $numeroPcg,
        'intitule' => "Compte {$numeroPcg}",
        'classe' => $classe,
        'actif' => $actif,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);
}

it('groupesPourType(depense) ne retourne que les comptes de classe 6, groupés et triés', function () {
    $associationId = TenantContext::currentId();

    Famille::factory()->create(['association_id' => $associationId, 'code' => '61', 'nom' => 'Charges de fonctionnement']);
    Famille::factory()->create(['association_id' => $associationId, 'code' => '62', 'nom' => 'Services extérieurs']);

    creerCompte($associationId, '622A', 6);
    creerCompte($associationId, '611A', 6);
    creerCompte($associationId, '706A', 7); // classe 7 — ne doit pas apparaître

    $groupes = PlanComptableSelecteur::groupesPourType('depense');

    // Note : les clés '61'/'62' sont des chaînes numériques — PHP les caste
    // en int dans les clés de tableau (comportement natif, déjà présent dans
    // PlanComptable::render()::groupBy()). ->get('61') fonctionne malgré
    // tout (cast implicite symétrique à la lecture), d'où la comparaison via
    // map(strval) plutôt qu'un ->toBe(['61', '62']) qui casserait sur le typage.
    expect($groupes->keys()->map(fn ($k) => (string) $k)->all())->toBe(['61', '62']);

    $groupe61 = $groupes->get('61');
    expect($groupe61['famille']->nom)->toBe('Charges de fonctionnement')
        ->and($groupe61['comptes']->pluck('numero_pcg')->all())->toBe(['611A']);

    $groupe62 = $groupes->get('62');
    expect($groupe62['famille']->nom)->toBe('Services extérieurs')
        ->and($groupe62['comptes']->pluck('numero_pcg')->all())->toBe(['622A']);
});

it('groupesPourType(recette) ne retourne que les comptes de classe 7', function () {
    $associationId = TenantContext::currentId();

    creerCompte($associationId, '706A', 7);
    creerCompte($associationId, '7411', 7);
    creerCompte($associationId, '611A', 6); // classe 6 — ne doit pas apparaître

    $groupes = PlanComptableSelecteur::groupesPourType('recette');

    // Cf. note ci-dessus : clés castées en int par PHP, comparaison en string.
    expect($groupes->keys()->map(fn ($k) => (string) $k)->all())->toBe(['70', '74']);

    $tousLesComptes = $groupes->flatMap(fn (array $g) => $g['comptes']->pluck('numero_pcg'));
    expect($tousLesComptes->all())->toBe(['706A', '7411']);
});

it('trie les comptes par numero_pcg à l intérieur d une même famille', function () {
    $associationId = TenantContext::currentId();

    // Créés dans le désordre : la requête ORDER BY numero_pcg doit primer,
    // et groupBy() doit préserver cet ordre relatif à l'intérieur du groupe.
    creerCompte($associationId, '706C', 7);
    creerCompte($associationId, '706A', 7);
    creerCompte($associationId, '706B', 7);

    $groupes = PlanComptableSelecteur::groupesPourType('recette');

    expect($groupes->get('70')['comptes']->pluck('numero_pcg')->all())
        ->toBe(['706A', '706B', '706C']);
});

it('exclut les comptes inactifs', function () {
    $associationId = TenantContext::currentId();

    creerCompte($associationId, '706A', 7, actif: true);
    creerCompte($associationId, '706B', 7, actif: false);

    $groupes = PlanComptableSelecteur::groupesPourType('recette');

    expect($groupes->get('70')['comptes']->pluck('numero_pcg')->all())
        ->toBe(['706A']);
});

it('inclut un compte dont la famille n a pas de ligne Famille (famille => null)', function () {
    $associationId = TenantContext::currentId();

    // Insertion via DB::table (pas Compte::create/forceCreate) : évite que
    // CompteObserver::created auto-matérialise la famille orpheline, pour
    // isoler le comportement du read helper sur un compte sans Famille.
    DB::table('comptes')->insert([
        'association_id' => $associationId,
        'numero_pcg' => '706A',
        'intitule' => 'Compte 706A',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Famille::where('code', '70')->exists())->toBeFalse();

    $groupes = PlanComptableSelecteur::groupesPourType('recette');

    expect($groupes->has('70'))->toBeTrue()
        ->and($groupes->get('70')['famille'])->toBeNull()
        ->and($groupes->get('70')['comptes']->pluck('numero_pcg')->all())->toBe(['706A']);

    // Toujours aucune Famille créée en base après l'appel : le helper est
    // strictement en lecture.
    expect(Famille::where('code', '70')->exists())->toBeFalse();
});

it('n expose pas les comptes d une autre association (isolation tenant)', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso2);
    creerCompte((int) $asso2->id, '706A', 7);

    TenantContext::boot($asso1);
    creerCompte((int) $asso1->id, '706A', 7);

    $groupes = PlanComptableSelecteur::groupesPourType('recette');

    expect($groupes->get('70')['comptes'])->toHaveCount(1)
        ->and((int) $groupes->get('70')['comptes']->first()->association_id)->toBe((int) $asso1->id);
});

it('lève une InvalidArgumentException pour un type invalide', function () {
    PlanComptableSelecteur::groupesPourType('virement');
})->throws(InvalidArgumentException::class, 'Type de ventilation invalide : virement');

// -----------------------------------------------------------------------
// Partial Blade select-compte-options
// -----------------------------------------------------------------------

it('rend les optgroup et option attendus pour les groupes fournis', function () {
    $associationId = TenantContext::currentId();

    Famille::factory()->create(['association_id' => $associationId, 'code' => '70', 'nom' => 'Ventes et prestations']);

    creerCompte($associationId, '706A', 7);
    creerCompte($associationId, '7411', 7);

    $groupes = PlanComptableSelecteur::groupesPourType('recette');

    $html = view('partials.select-compte-options', ['groupes' => $groupes])->render();

    expect($html)
        ->toContain('<optgroup label="70 — Ventes et prestations">')
        ->toContain('Compte 706A')
        ->toContain('Compte 7411');

    $compte706A = $groupes->get('70')['comptes']->firstWhere('numero_pcg', '706A');
    expect($html)->toContain('<option value="'.$compte706A->id.'"');
});

it('marque l option correspondant à selectedId comme selected', function () {
    $associationId = TenantContext::currentId();

    creerCompte($associationId, '706A', 7);
    $compteB = creerCompte($associationId, '706B', 7);

    $groupes = PlanComptableSelecteur::groupesPourType('recette');

    $html = view('partials.select-compte-options', [
        'groupes' => $groupes,
        'selectedId' => $compteB->id,
    ])->render();

    expect($html)->toContain('value="'.$compteB->id.'" selected');
});

it('ne marque aucune option selected quand selectedId est absent', function () {
    $associationId = TenantContext::currentId();

    creerCompte($associationId, '706A', 7);

    $groupes = PlanComptableSelecteur::groupesPourType('recette');

    $html = view('partials.select-compte-options', ['groupes' => $groupes])->render();

    expect($html)->not->toContain('selected');
});
