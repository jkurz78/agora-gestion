<?php

declare(strict_types=1);

use App\Enums\CasProvision;
use App\Services\Compta\SchemaRattachement;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| SchemaRattachement : la source unique du sens, des dates et du libellé
|--------------------------------------------------------------------------
|
| Les deux écritures d'un rattachement (dotation et extourne), calculées SANS rien écrire. Le
| générateur (EcritureGenerator::pourRattachement) les écrit, l'écran (l'aperçu de la modale) les
| montre : l'une et l'autre lisent cette méthode, jamais une règle de leur côté (spec § 6.3 — c'est
| l'écriture qu'on n'avait jamais regardée qui a laissé passer l'inversion de juin).
|
| Ces tests portent sur la méthode seule, pour les QUATRE cas (FNP et PAR sont du lot 2, mais le
| schéma est déjà celui du § 5). Que le générateur écrive bien ce que la méthode rend, et que
| l'aperçu l'affiche, se prouve dans RattachementEcrituresTest et RattachementIndexTest.
*/

dataset('schema_rattachement_quatre_cas', [
    // cas, dotation [résultat D, résultat C, contrepartie D, contrepartie C], extourne idem
    'PCA : 7xx D / 487 C, puis 487 D / 7xx C' => [CasProvision::ProduitConstateDavance, '706B', '487', [175.0, 0.0, 0.0, 175.0], [0.0, 175.0, 175.0, 0.0]],
    'CCA : 486 D / 6xx C, puis 6xx D / 486 C' => [CasProvision::ChargeConstateeDavance, '606', '486', [0.0, 175.0, 175.0, 0.0], [175.0, 0.0, 0.0, 175.0]],
    'PAR : 418 D / 7xx C, puis 7xx D / 418 C' => [CasProvision::ProduitARecevoir, '706B', '418', [0.0, 175.0, 175.0, 0.0], [175.0, 0.0, 0.0, 175.0]],
    'FNP : 6xx D / 408 C, puis 408 D / 6xx C' => [CasProvision::FactureNonParvenue, '606', '408', [175.0, 0.0, 0.0, 175.0], [0.0, 175.0, 175.0, 0.0]],
]);

test('le sens de la dotation et de l’extourne suit le § 5 pour les quatre cas', function (CasProvision $cas, string $compteResultat, string $contrepartie, array $dotation, array $extourne) {
    $schema = app(SchemaRattachement::class);

    foreach ([[false, $dotation], [true, $extourne]] as [$estExtourne, [$resultatD, $resultatC, $contrepartieD, $contrepartieC]]) {
        $jambes = $schema->ecriture($cas, $compteResultat, 175.0, 2025, 'Parcours', $estExtourne)['lignes'];

        expect($jambes['resultat'])->toBe(['numero_pcg' => $compteResultat, 'debit' => $resultatD, 'credit' => $resultatC]);
        expect($jambes['contrepartie'])->toBe(['numero_pcg' => $contrepartie, 'debit' => $contrepartieD, 'credit' => $contrepartieC]);
    }
})->with('schema_rattachement_quatre_cas');

test('chaque écriture est équilibrée et ne porte ni débit ni crédit négatif, quel que soit le cas', function (CasProvision $cas, string $compteResultat) {
    foreach ([false, true] as $estExtourne) {
        $jambes = app(SchemaRattachement::class)->ecriture($cas, $compteResultat, 33.33, 2025, 'Parcours', $estExtourne)['lignes'];

        expect(round($jambes['resultat']['debit'] + $jambes['contrepartie']['debit'], 2))
            ->toBe(round($jambes['resultat']['credit'] + $jambes['contrepartie']['credit'], 2));

        foreach ($jambes as $jambe) {
            expect($jambe['debit'])->toBeGreaterThanOrEqual(0.0);
            expect($jambe['credit'])->toBeGreaterThanOrEqual(0.0);
        }
    }
})->with([
    'PCA' => [CasProvision::ProduitConstateDavance, '706B'],
    'CCA' => [CasProvision::ChargeConstateeDavance, '606'],
]);

test('l’extourne est exactement le miroir de la dotation : mêmes comptes, débit et crédit échangés', function (CasProvision $cas) {
    $schema = app(SchemaRattachement::class);
    $dotation = $schema->ecriture($cas, '706B', 175.0, 2025, 'Parcours', false)['lignes'];
    $extourne = $schema->ecriture($cas, '706B', 175.0, 2025, 'Parcours', true)['lignes'];

    foreach (['resultat', 'contrepartie'] as $role) {
        expect($extourne[$role]['numero_pcg'])->toBe($dotation[$role]['numero_pcg']);
        expect($extourne[$role]['debit'])->toBe($dotation[$role]['credit']);
        expect($extourne[$role]['credit'])->toBe($dotation[$role]['debit']);
    }
})->with([CasProvision::ProduitConstateDavance, CasProvision::ChargeConstateeDavance]);

test('le montant net porté sur la pièce est celui du résultat, signé : négatif pour la dotation, positif pour l’extourne (PCA et CCA)', function (CasProvision $cas) {
    $schema = app(SchemaRattachement::class);

    expect($schema->ecriture($cas, '706B', 175.0, 2025, 'Parcours', false)['montant_total'])->toBe(-175.0);
    expect($schema->ecriture($cas, '706B', 175.0, 2025, 'Parcours', true)['montant_total'])->toBe(175.0);
})->with([CasProvision::ProduitConstateDavance, CasProvision::ChargeConstateeDavance]);

test('la dotation est datée du dernier jour de N, l’extourne du premier jour de N+1', function () {
    $schema = app(SchemaRattachement::class);
    $cas = CasProvision::ProduitConstateDavance;

    expect($schema->ecriture($cas, '706B', 175.0, 2025, 'Parcours', false)['date']->toDateString())->toBe('2026-08-31');
    expect($schema->ecriture($cas, '706B', 175.0, 2025, 'Parcours', true)['date']->toDateString())->toBe('2026-09-01');
});

test('les dates viennent du paramétrage de l’association : un exercice civil donne le 31/12 et le 01/01', function () {
    DB::table('association')->where('id', TenantContext::currentId())->update(['exercice_mois_debut' => 1]);
    TenantContext::boot(TenantContext::current()->fresh());

    $schema = app(SchemaRattachement::class);
    $cas = CasProvision::ProduitConstateDavance;

    expect($schema->ecriture($cas, '706B', 175.0, 2026, 'Parcours', false)['date']->toDateString())->toBe('2026-12-31');
    expect($schema->ecriture($cas, '706B', 175.0, 2026, 'Parcours', true)['date']->toDateString())->toBe('2027-01-01');
});

test('le libellé nomme le cas en toutes lettres et reprend celui de l’origine', function () {
    $schema = app(SchemaRattachement::class);

    expect($schema->ecriture(CasProvision::ProduitConstateDavance, '706B', 175.0, 2025, 'Parcours', false)['libelle'])
        ->toBe('Rattachement — produit constaté d’avance : Parcours');
    expect($schema->ecriture(CasProvision::ChargeConstateeDavance, '606', 175.0, 2025, 'Parcours', true)['libelle'])
        ->toBe('Extourne — charge constatée d’avance : Parcours');
});

test('le libellé est tronqué à la colonne : 255 caractères exactement, terminés par « … »', function () {
    $libelle = app(SchemaRattachement::class)
        ->ecriture(CasProvision::ProduitConstateDavance, '706B', 175.0, 2025, str_repeat('é', 400), false)['libelle'];

    expect(mb_strlen($libelle))->toBe(255);
    expect(mb_substr($libelle, -1))->toBe('…');
});

test('la méthode est pure : elle ne lit que le paramétrage et n’écrit rien', function () {
    $ecritures = [];

    DB::listen(function ($requete) use (&$ecritures): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $requete->sql) === 1) {
            $ecritures[] = $requete->sql;
        }
    });

    app(SchemaRattachement::class)->ecriture(CasProvision::ProduitConstateDavance, '706B', 175.0, 2025, 'Parcours', false);
    app(SchemaRattachement::class)->ecriture(CasProvision::ProduitConstateDavance, '706B', 175.0, 2025, 'Parcours', true);

    expect($ecritures)->toBe([]);
});
