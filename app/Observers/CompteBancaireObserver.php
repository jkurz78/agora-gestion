<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Compte;
use App\Models\CompteBancaire;

/**
 * Propage les attributs bancaires de la fiche CompteBancaire vers le compte 512X
 * correspondant du plan comptable.
 *
 * `BancairesSeeder` recopie ces attributs dans `comptes` en INSERT IGNORE : la
 * copie n'est faite qu'à la création du compte, jamais rafraîchie. Sans cet
 * observer, toute correction ultérieure sur la fiche bancaire reste invisible du
 * moteur comptable, qui ne lit que `comptes` — divergence silencieuse.
 *
 * Constaté en recette le 2026-07-29 : date du solde initial corrigée au
 * 31/08/2024 sur la fiche, restée au 31/08/2025 sur le compte 5121. L'à-nouveau
 * de clôture s'est appuyé sur la valeur périmée et a produit des soldes
 * d'ouverture faux, sans le moindre signal.
 *
 * L'intitulé n'est volontairement PAS propagé : le libellé d'un compte est
 * librement modifiable côté plan comptable (décision D3 de la dissolution
 * sous_categories → comptes) et peut légitimement différer du nom de la banque.
 */
final class CompteBancaireObserver
{
    /** @var list<string> */
    private const ATTRIBUTS_PROPAGES = [
        'iban',
        'bic',
        'domiciliation',
        'solde_initial',
        'date_solde_initial',
    ];

    public function updated(CompteBancaire $compteBancaire): void
    {
        $modifies = array_filter(
            self::ATTRIBUTS_PROPAGES,
            static fn (string $attribut): bool => $compteBancaire->wasChanged($attribut),
        );

        if ($modifies === []) {
            return;
        }

        $compte = Compte::where('compte_bancaire_id', (int) $compteBancaire->id)->first();

        if ($compte === null) {
            return;
        }

        $compte->forceFill(
            array_combine(
                $modifies,
                array_map(
                    static fn (string $attribut): mixed => $compteBancaire->getAttribute($attribut),
                    $modifies,
                ),
            )
        )->save();
    }
}
