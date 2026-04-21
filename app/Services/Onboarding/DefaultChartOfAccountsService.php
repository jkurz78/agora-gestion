<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use Illuminate\Support\Facades\DB;

final class DefaultChartOfAccountsService
{
    /**
     * @return array<string, int> counts of created rows
     */
    public function applyTo(Association $association): array
    {
        $data = $this->defaultStructure();
        $categoriesCreated = 0;
        $sousCategoriesCreated = 0;

        DB::transaction(function () use ($association, $data, &$categoriesCreated, &$sousCategoriesCreated): void {
            foreach ($data as $cat) {
                $categorie = Categorie::create([
                    'association_id' => $association->id,
                    'nom' => $cat['nom'],
                    'type' => $cat['type'],
                ]);
                $categoriesCreated++;

                foreach ($cat['sous'] as $sc) {
                    $usages = $sc['usages'] ?? [];
                    unset($sc['usages']);

                    $sousCategorie = SousCategorie::create(array_merge([
                        'association_id' => $association->id,
                        'categorie_id' => $categorie->id,
                    ], $sc));
                    $sousCategoriesCreated++;

                    foreach ($usages as $usage) {
                        UsageSousCategorie::create([
                            'association_id' => $association->id,
                            'sous_categorie_id' => $sousCategorie->id,
                            'usage' => $usage->value,
                        ]);
                    }
                }
            }
        });

        return ['categories' => $categoriesCreated, 'sous_categories' => $sousCategoriesCreated];
    }

    /**
     * @return list<array{nom: string, type: TypeCategorie, sous: list<array<string, scalar>>}>
     */
    private function defaultStructure(): array
    {
        return [
            [
                'nom' => '60 - Achats',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Fournitures',        'code_cerfa' => '606'],
                    ['nom' => 'Petits équipements', 'code_cerfa' => '606B'],
                    ['nom' => 'Achats divers',      'code_cerfa' => '609'],
                ],
            ],
            [
                'nom' => '61 - Charges de fonctionnement',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Location salle',                     'code_cerfa' => '613A'],
                    ['nom' => 'Location lieu (centre équestre)',    'code_cerfa' => '613B'],
                    ['nom' => "Location lieu (salle d'armes)",      'code_cerfa' => '613C'],
                ],
            ],
            [
                'nom' => '62 - Autres services extérieurs',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Bilan pré-thérapeutique',  'code_cerfa' => '611A'],
                    ['nom' => 'Animation / Encadrement',  'code_cerfa' => '611B'],
                    ['nom' => 'Supervision',              'code_cerfa' => '611C'],
                    ['nom' => 'Sessions inter-ateliers',  'code_cerfa' => '611D'],
                    ['nom' => 'Honoraires juridiques',    'code_cerfa' => '622'],
                    ['nom' => 'Frais de déplacements',    'code_cerfa' => '625A', 'usages' => [UsageComptable::FraisKilometriques]],
                    ['nom' => 'Repas / Restauration',     'code_cerfa' => '625B'],
                    ['nom' => 'Locations de logiciels',   'code_cerfa' => '628A'],
                    ['nom' => 'Hébergement internet',     'code_cerfa' => '628B'],
                    ['nom' => 'Développement logiciel',   'code_cerfa' => '628C'],
                ],
            ],
            [
                'nom' => '66 - Charges financières',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Frais bancaires', 'code_cerfa' => '627'],
                ],
            ],
            [
                'nom' => '70 - Ventes et prestations',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Formations',              'code_cerfa' => '706A', 'usages' => [UsageComptable::Inscription]],
                    ['nom' => 'Parcours thérapeutiques', 'code_cerfa' => '706B', 'usages' => [UsageComptable::Inscription]],
                    ['nom' => 'Ventes de produits',      'code_cerfa' => '707'],
                ],
            ],
            [
                'nom' => '74 - Subventions',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Subvention État Ministère des Sports', 'code_cerfa' => '741'],
                ],
            ],
            [
                'nom' => '75 - Cotisations et dons',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Cotisations', 'code_cerfa' => '751', 'usages' => [UsageComptable::Cotisation]],
                    ['nom' => 'Dons manuels', 'code_cerfa' => '754', 'usages' => [UsageComptable::Don]],
                    ['nom' => 'Mécénat',     'code_cerfa' => '756'],
                ],
            ],
            [
                'nom' => '76 - Produits financiers',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Intérêts', 'code_cerfa' => '761'],
                ],
            ],
            [
                'nom' => '77 - Produits exceptionnels',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Abandon de créance', 'code_cerfa' => '771', 'usages' => [UsageComptable::Don, UsageComptable::AbandonCreance]],
                ],
            ],
        ];
    }
}
