<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Les quatre écritures de rattachement à l'exercice.
 *
 * Ce ne sont PAS des provisions au sens comptable : 486 et 487 sont des comptes
 * de régularisation (classe 48). Les provisions vivent en 15/29/39/49/59 et ne
 * sont pas concernées ici.
 *
 * Le cas n'est jamais saisi ni stocké : il se déduit de la classe du compte et
 * de l'adossement à une écriture d'origine (voir Provision::cas()).
 */
enum CasProvision: string
{
    case ChargeConstateeDavance = 'cca';
    case ProduitConstateDavance = 'pca';
    case FactureNonParvenue = 'fnp';
    case ProduitARecevoir = 'par';

    public function label(): string
    {
        return match ($this) {
            self::ChargeConstateeDavance => 'Charge constatée d’avance',
            self::ProduitConstateDavance => 'Produit constaté d’avance',
            self::FactureNonParvenue => 'Facture non parvenue',
            self::ProduitARecevoir => 'Produit à recevoir',
        };
    }

    /**
     * Déduit le cas de ses deux déterminants : la classe du compte de résultat
     * et l'adossement à une écriture d'origine.
     *
     * Bijection avec classe() et estAdosse() : pour tout cas $c,
     * deduire($c->classe(), $c->estAdosse()) === $c. Les deux moitiés vivent ici,
     * côte à côte, pour qu'on ne puisse pas les modifier l'une sans l'autre.
     *
     * classe 6 + adossé = CCA, classe 7 + adossé = PCA,
     * classe 6 + libre  = FNP, classe 7 + libre  = PAR.
     *
     * @throws InvalidArgumentException si la classe n'est ni 6 ni 7
     */
    public static function deduire(int $classe, bool $adossee): self
    {
        return match (true) {
            $classe === 6 && $adossee => self::ChargeConstateeDavance,
            $classe === 7 && $adossee => self::ProduitConstateDavance,
            $classe === 6 => self::FactureNonParvenue,
            $classe === 7 => self::ProduitARecevoir,
            default => throw new InvalidArgumentException(
                "Rattachement : un compte de classe {$classe} ne porte aucun cas (classe 6 ou 7 attendue)."
            ),
        };
    }

    /** Vrai quand l'écriture s'adosse à une ligne d'origine (CCA, PCA) plutôt qu'à une saisie libre (FNP, PAR). */
    public function estAdosse(): bool
    {
        return match ($this) {
            self::ChargeConstateeDavance, self::ProduitConstateDavance => true,
            self::FactureNonParvenue, self::ProduitARecevoir => false,
        };
    }

    public function sigle(): string
    {
        return mb_strtoupper($this->value);
    }

    /** Classe PCG du compte de résultat mouvementé. */
    public function classe(): int
    {
        return match ($this) {
            self::ChargeConstateeDavance, self::FactureNonParvenue => 6,
            self::ProduitConstateDavance, self::ProduitARecevoir => 7,
        };
    }

    /** Compte de régularisation ou de rattachement, en contrepartie. */
    public function compteContrepartie(): string
    {
        return match ($this) {
            self::ChargeConstateeDavance => '486',
            self::ProduitConstateDavance => '487',
            self::FactureNonParvenue => '408',
            self::ProduitARecevoir => '418',
        };
    }

    /**
     * Vrai quand la dotation DÉBITE le compte de résultat.
     *
     * PCA : 7xx D / 487 C — on retire le produit de N.
     * CCA : 486 D / 6xx C — on retire la charge de N, le débit est sur le 486.
     */
    public function dotationDebiteLeResultat(): bool
    {
        return match ($this) {
            self::ProduitConstateDavance, self::FactureNonParvenue => true,
            self::ChargeConstateeDavance, self::ProduitARecevoir => false,
        };
    }
}
