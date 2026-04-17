<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\TypeCategorie;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
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
                    SousCategorie::create(array_merge([
                        'association_id' => $association->id,
                        'categorie_id' => $categorie->id,
                        'pour_dons' => false,
                        'pour_cotisations' => false,
                        'pour_inscriptions' => false,
                    ], $sc));
                    $sousCategoriesCreated++;
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
                'nom' => '70 - Ventes et prestations',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Formations',              'code_cerfa' => '706A', 'pour_inscriptions' => true],
                    ['nom' => 'Parcours thérapeutiques', 'code_cerfa' => '706B', 'pour_inscriptions' => true],
                    ['nom' => 'Ventes de produits',      'code_cerfa' => '707'],
                ],
            ],
            [
                'nom' => '74 - Subventions',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Subventions publiques', 'code_cerfa' => '740'],
                ],
            ],
            [
                'nom' => '75 - Cotisations',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Cotisations adhérents', 'code_cerfa' => '754', 'pour_cotisations' => true],
                ],
            ],
            [
                'nom' => '75 - Dons',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Dons éligibles au mécénat', 'code_cerfa' => '754', 'pour_dons' => true],
                ],
            ],
            [
                'nom' => '60 - Achats',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Matières premières', 'code_cerfa' => '601'],
                    ['nom' => 'Fournitures',        'code_cerfa' => '606'],
                ],
            ],
            [
                'nom' => '62 - Services extérieurs',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Honoraires',   'code_cerfa' => '622'],
                    ['nom' => 'Publicité',    'code_cerfa' => '623'],
                    ['nom' => 'Déplacements', 'code_cerfa' => '625'],
                ],
            ],
            [
                'nom' => '64 - Charges de personnel',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Salaires et traitements', 'code_cerfa' => '641'],
                ],
            ],
        ];
    }
}
