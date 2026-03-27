<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        SousCategorie::truncate();
        Categorie::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $data = [
            // ─── RECETTES ───────────────────────────────────────────────
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
                    ['nom' => 'Subvention État Ministère des Sports', 'code_cerfa' => '741'],
                ],
            ],
            [
                'nom' => '75 - Cotisations et dons',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Cotisations',  'code_cerfa' => '751', 'pour_cotisations' => true],
                    ['nom' => 'Dons manuels', 'code_cerfa' => '754', 'pour_dons' => true],
                    ['nom' => 'Mécénat',      'code_cerfa' => '756', 'pour_dons' => true],
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
                    ['nom' => 'Abandon de créance', 'code_cerfa' => '771', 'pour_dons' => true],
                ],
            ],

            // ─── DÉPENSES ───────────────────────────────────────────────
            [
                'nom' => '60 - Achats',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Fournitures',      'code_cerfa' => '606'],
                    ['nom' => 'Petits équipements', 'code_cerfa' => '606B'],
                    ['nom' => 'Achats divers',     'code_cerfa' => '609'],
                ],
            ],
            [
                'nom' => '61 - Charges de fonctionnement',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Location salle',                     'code_cerfa' => '613A'],
                    ['nom' => 'Location lieu (centre équestre)',     'code_cerfa' => '613B'],
                    ['nom' => 'Location lieu (salle d\'armes)',      'code_cerfa' => '613C'],
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
                    ['nom' => 'Frais de déplacements',    'code_cerfa' => '625A'],
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
                'nom' => '67 - Charges exceptionnelles',
                'type' => TypeCategorie::Depense,
                'sous' => [],
            ],
        ];

        foreach ($data as $item) {
            $categorie = Categorie::create([
                'nom' => $item['nom'],
                'type' => $item['type'],
            ]);

            foreach ($item['sous'] as $sous) {
                $categorie->sousCategories()->create($sous);
            }
        }
    }
}
