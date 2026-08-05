<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Models\Compte;
use App\Models\Famille;
use App\Tenant\TenantContext;

/**
 * Kit minimal de comptes nécessaires aux immobilisations, créé à la demande.
 *
 * Idempotent : un rejeu est un no-op sur les comptes et familles déjà présents.
 * Même intention que ComptesProvisioningService, restreinte aux immobilisations.
 */
final class ImmobilisationComptesSeeder
{
    /** @var array<string, string> numero_pcg => intitulé */
    private const COMPTES = [
        '2154' => 'Matériel',
        '2183' => 'Matériel de bureau et informatique',
        '2184' => 'Mobilier',
        '2188' => 'Autres immobilisations corporelles',
        '28154' => 'Amortissements du matériel',
        '28183' => 'Amortissements du matériel de bureau et informatique',
        '28184' => 'Amortissements du mobilier',
        '28188' => 'Amortissements des autres immobilisations corporelles',
        '6811' => 'Dotations aux amortissements sur immobilisations corporelles',
    ];

    /** @var array<string, string> code famille => nom */
    private const FAMILLES = [
        '21' => 'Immobilisations corporelles',
        '28' => 'Amortissements des immobilisations',
        '68' => 'Dotations aux amortissements',
    ];

    public static function seed(): void
    {
        $associationId = (int) TenantContext::currentId();

        foreach (self::FAMILLES as $code => $nom) {
            // PHP caste les clés de tableau numériques en int ('21' → 21) :
            // recast en string, sinon Famille::code (varchar) reçoit un int.
            $code = (string) $code;

            Famille::firstOrCreate(
                ['association_id' => $associationId, 'code' => $code],
                ['nom' => $nom],
            );
        }

        foreach (self::COMPTES as $numero => $intitule) {
            // Même piège que ci-dessus : '2154' devient la clé int 2154, et
            // substr() sur un int lève un TypeError sous strict_types.
            $numero = (string) $numero;

            Compte::firstOrCreate(
                ['association_id' => $associationId, 'numero_pcg' => $numero],
                [
                    'intitule' => $intitule,
                    'classe' => (int) substr($numero, 0, 1),
                    'actif' => true,
                    'lettrable' => false,
                    // 6811 est un compte de convention, résolu par son numéro
                    // exactement comme 401/411 : protégé comme eux. Les 21XX/281XX
                    // restent ordinaires — choisis par l'utilisateur, renommables.
                    'est_systeme' => $numero === '6811',
                ],
            );
        }
    }

    /**
     * Compte d'amortissement dérivé du compte d'immobilisation par la règle PCG :
     * on insère un « 8 » après le premier chiffre (2154 → 28154).
     *
     * Retourne null si le compte dérivé n'existe pas dans le plan du tenant.
     */
    public static function compteAmortissementPour(Compte $compteImmobilisation): ?Compte
    {
        $numero = (string) $compteImmobilisation->numero_pcg;

        return Compte::ofNumero('2'.'8'.substr($numero, 1));
    }
}
