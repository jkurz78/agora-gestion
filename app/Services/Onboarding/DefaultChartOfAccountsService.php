<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\UsageSousCategorie;
use Illuminate\Support\Facades\DB;

/**
 * DC-9 du programme « dissolution sous_categories → comptes ».
 *
 * Provisionne le plan comptable par défaut à l'onboarding en créant
 * `Famille` + `Compte` comme objets PRIMAIRES (comptes/familles = source de
 * vérité). Le miroir `CompteObserver` matérialise automatiquement la
 * `SousCategorie` (et sa `Categorie` de secours) pour le pont CR legacy, tant
 * que `sous_categories` existe (drop prévu en DC-10).
 *
 * Avant DC-9, ce service créait Categorie/SousCategorie en primaire et
 * laissait `SousCategorieCompteObserver` matérialiser les comptes en miroir.
 */
final class DefaultChartOfAccountsService
{
    /**
     * @return array<string, int> counts of created rows (compte-first ;
     *                            'categories'/'sous_categories' reflètent le
     *                            miroir matérialisé en retour, pour compat
     *                            ascendante des appelants existants)
     */
    public function applyTo(Association $association): array
    {
        $data = $this->defaultStructure();
        $famillesCreated = 0;
        $comptesCreated = 0;

        DB::transaction(function () use ($association, $data, &$famillesCreated, &$comptesCreated): void {
            foreach ($data as $fam) {
                Famille::create([
                    'association_id' => $association->id,
                    'code' => $fam['code'],
                    'nom' => $fam['nom'],
                ]);
                $famillesCreated++;

                foreach ($fam['comptes'] as $c) {
                    $usages = $c['usages'] ?? [];
                    unset($c['usages']);

                    $compte = Compte::create(array_merge([
                        'association_id' => $association->id,
                        'classe' => (int) $fam['code'][0],
                        'actif' => true,
                        'est_systeme' => false,
                        'pour_inscriptions' => false,
                        'lettrable' => false,
                    ], $c));
                    $comptesCreated++;

                    foreach ($usages as $usage) {
                        UsageSousCategorie::create([
                            'association_id' => $association->id,
                            'compte_id' => $compte->id,
                            'usage' => $usage->value,
                        ]);
                    }
                }
            }
        });

        return [
            'categories' => $famillesCreated,
            'sous_categories' => $comptesCreated,
            'familles' => $famillesCreated,
            'comptes' => $comptesCreated,
        ];
    }

    /**
     * @return list<array{code: string, nom: string, comptes: list<array<string, scalar>>}>
     */
    private function defaultStructure(): array
    {
        return [
            [
                'code' => '60',
                'nom' => 'Achats',
                'comptes' => [
                    ['intitule' => 'Fournitures',        'numero_pcg' => '606'],
                    ['intitule' => 'Petits équipements', 'numero_pcg' => '606B'],
                    ['intitule' => 'Achats divers',      'numero_pcg' => '609'],
                ],
            ],
            [
                'code' => '61',
                'nom' => 'Charges de fonctionnement',
                'comptes' => [
                    ['intitule' => 'Location salle',                     'numero_pcg' => '613A'],
                    ['intitule' => 'Location lieu (centre équestre)',    'numero_pcg' => '613B'],
                    ['intitule' => "Location lieu (salle d'armes)",      'numero_pcg' => '613C'],
                ],
            ],
            [
                'code' => '62',
                'nom' => 'Autres services extérieurs',
                'comptes' => [
                    ['intitule' => 'Bilan pré-thérapeutique',  'numero_pcg' => '611A'],
                    ['intitule' => 'Animation / Encadrement',  'numero_pcg' => '611B'],
                    ['intitule' => 'Supervision',              'numero_pcg' => '611C'],
                    ['intitule' => 'Sessions inter-ateliers',  'numero_pcg' => '611D'],
                    ['intitule' => 'Honoraires juridiques',    'numero_pcg' => '622'],
                    ['intitule' => 'Frais de déplacements',    'numero_pcg' => '625A', 'usages' => [UsageComptable::FraisKilometriques]],
                    ['intitule' => 'Repas / Restauration',     'numero_pcg' => '625B'],
                    ['intitule' => 'Locations de logiciels',   'numero_pcg' => '628A'],
                    ['intitule' => 'Hébergement internet',     'numero_pcg' => '628B'],
                    ['intitule' => 'Développement logiciel',   'numero_pcg' => '628C'],
                    // Numéro PCG réel 627 (Services bancaires) → préfixe 62,
                    // pas 66. Le rattachement famille est DÉRIVÉ par préfixe
                    // (Famille::code sur les 2 premiers caractères de
                    // numero_pcg, pas de FK — voir App\Models\Famille) : un
                    // compte 627 ne peut structurellement PAS appartenir à la
                    // famille 66, quel que soit le libellé Categorie qu'on lui
                    // donnait avant DC-9 (bucket cosmétique côté Categorie/
                    // SousCategorie, aucune contrainte de préfixe). Nichée ici
                    // pour que la famille dérivée corresponde à son numéro.
                    ['intitule' => 'Frais bancaires', 'numero_pcg' => '627'],
                ],
            ],
            [
                'code' => '70',
                'nom' => 'Ventes et prestations',
                'comptes' => [
                    ['intitule' => 'Formations',              'numero_pcg' => '706A', 'usages' => [UsageComptable::Inscription]],
                    ['intitule' => 'Parcours thérapeutiques', 'numero_pcg' => '706B', 'usages' => [UsageComptable::Inscription]],
                    ['intitule' => 'Ventes de produits',      'numero_pcg' => '707'],
                ],
            ],
            [
                'code' => '74',
                'nom' => 'Subventions',
                'comptes' => [
                    ['intitule' => 'Subvention État Ministère des Sports', 'numero_pcg' => '741'],
                ],
            ],
            [
                'code' => '75',
                'nom' => 'Cotisations et dons',
                'comptes' => [
                    ['intitule' => 'Cotisations', 'numero_pcg' => '751', 'usages' => [UsageComptable::Cotisation]],
                    ['intitule' => 'Dons manuels', 'numero_pcg' => '754', 'usages' => [UsageComptable::Don]],
                    ['intitule' => 'Mécénat',     'numero_pcg' => '756'],
                ],
            ],
            [
                'code' => '76',
                'nom' => 'Produits financiers',
                'comptes' => [
                    ['intitule' => 'Intérêts', 'numero_pcg' => '761'],
                ],
            ],
            [
                'code' => '77',
                'nom' => 'Produits exceptionnels',
                'comptes' => [
                    ['intitule' => 'Abandon de créance', 'numero_pcg' => '771', 'usages' => [UsageComptable::Don, UsageComptable::AbandonCreance]],
                ],
            ],
        ];
    }
}
