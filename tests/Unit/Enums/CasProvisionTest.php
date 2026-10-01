<?php

declare(strict_types=1);

use App\Enums\CasProvision;

/*
 * Ce que ces tests verrouillent, c'est le SENS des écritures de rattachement :
 * quelle classe, quelle contrepartie, et si la dotation débite ou crédite le
 * compte de résultat. Une erreur de sens ne fait planter rien : elle produit
 * des écritures bien équilibrées et fausses. C'est ainsi que l'ancien écran
 * a vécu dix-huit mois en débitant le 487 comme s'il était un produit (spec § 2.1).
 *
 * Le tableau ci-dessous est volontairement écrit en dur, à la main, et non
 * recalculé depuis l'enum : un test qui relit le code qu'il vérifie ne vérifie rien.
 *
 * Colonnes : [cas, classe du compte de résultat, contrepartie,
 *             la dotation débite le résultat ?, adossé à une origine ?]
 *
 * @return array<string, array{0: CasProvision, 1: int, 2: string, 3: bool, 4: bool}>
 */
function referenceCasProvision(): array
{
    return [
        // 486 D / 6xx C : la charge de N est retirée
        'CCA — charge constatée d’avance' => [CasProvision::ChargeConstateeDavance, 6, '486', false, true],
        // 7xx D / 487 C : le produit de N est retiré
        'PCA — produit constaté d’avance' => [CasProvision::ProduitConstateDavance, 7, '487', true, true],
        // 6xx D / 408 C : une charge de N est ajoutée
        'FNP — facture non parvenue' => [CasProvision::FactureNonParvenue, 6, '408', true, false],
        // 418 D / 7xx C : un produit de N est ajouté
        'PAR — produit à recevoir' => [CasProvision::ProduitARecevoir, 7, '418', false, false],
    ];
}

dataset('cas_provision', referenceCasProvision());

it('le tableau de référence couvre exactement les cas de l’enum', function (): void {
    $attendus = array_map(fn (CasProvision $c) => $c->name, CasProvision::cases());
    $couverts = array_map(fn (array $ligne) => $ligne[0]->name, array_values(referenceCasProvision()));

    sort($attendus);
    sort($couverts);

    expect($couverts)->toBe($attendus);
});

it('rattache chaque cas à la bonne classe de compte de résultat', function (CasProvision $cas, int $classe): void {
    expect($cas->classe())->toBe($classe);
})->with('cas_provision');

it('contrepasse chaque cas sur le bon compte de régularisation ou de rattachement', function (CasProvision $cas, int $classe, string $contrepartie): void {
    expect($cas->compteContrepartie())->toBe($contrepartie);
})->with('cas_provision');

it('fixe le sens de la dotation sur le compte de résultat', function (CasProvision $cas, int $classe, string $contrepartie, bool $debite): void {
    expect($cas->dotationDebiteLeResultat())->toBe($debite);
})->with('cas_provision');

it('sait si le cas s’adosse à une écriture d’origine', function (CasProvision $cas, int $classe, string $contrepartie, bool $debite, bool $adosse): void {
    expect($cas->estAdosse())->toBe($adosse);
})->with('cas_provision');

it('déduit le cas de la classe et de l’adossement, et inversement (bijection)', function (CasProvision $cas): void {
    expect(CasProvision::deduire($cas->classe(), $cas->estAdosse()))->toBe($cas);
})->with(fn () => array_combine(
    array_map(fn (CasProvision $c) => $c->name, CasProvision::cases()),
    array_map(fn (CasProvision $c) => [$c], CasProvision::cases()),
));

it('refuse de déduire un cas pour une classe qui n’est ni 6 ni 7', function (int $classe): void {
    expect(fn () => CasProvision::deduire($classe, true))
        ->toThrow(InvalidArgumentException::class, "classe {$classe}");
    expect(fn () => CasProvision::deduire($classe, false))
        ->toThrow(InvalidArgumentException::class, "classe {$classe}");
})->with([1, 4, 5, 8]);
