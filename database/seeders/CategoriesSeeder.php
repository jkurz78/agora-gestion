<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use Illuminate\Database\Seeder;

class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            // ─── RECETTES ───────────────────────────────────────────────
            [
                'nom'  => 'Cotisations et dons',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Cotisations des membres',        'code_cerfa' => '75A'],
                    ['nom' => 'Dons manuels',                   'code_cerfa' => '75B'],
                    ['nom' => 'Mécénat d\'entreprises',         'code_cerfa' => '75C'],
                    ['nom' => 'Legs et donations',              'code_cerfa' => '75D'],
                ],
            ],
            [
                'nom'  => 'Subventions',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Subventions de l\'État',                  'code_cerfa' => '74A'],
                    ['nom' => 'Subventions régionales',                  'code_cerfa' => '74B'],
                    ['nom' => 'Subventions départementales',             'code_cerfa' => '74C'],
                    ['nom' => 'Subventions communales',                  'code_cerfa' => '74D'],
                    ['nom' => 'Subventions européennes',                 'code_cerfa' => '74E'],
                    ['nom' => 'Subventions d\'autres organismes publics','code_cerfa' => '74F'],
                ],
            ],
            [
                'nom'  => 'Prestations et ventes',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Ventes de prestations de services', 'code_cerfa' => '70A'],
                    ['nom' => 'Ventes de produits',                'code_cerfa' => '70B'],
                    ['nom' => 'Droits d\'entrée et billetterie',   'code_cerfa' => '70C'],
                    ['nom' => 'Formations',                        'code_cerfa' => '70D'],
                    ['nom' => 'Publications et ventes diverses',   'code_cerfa' => '70E'],
                ],
            ],
            [
                'nom'  => 'Autres produits',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Produits financiers (intérêts)',  'code_cerfa' => '76A'],
                    ['nom' => 'Produits exceptionnels',          'code_cerfa' => '77A'],
                    ['nom' => 'Remboursements et récupérations', 'code_cerfa' => '79A'],
                    ['nom' => 'Recettes diverses',               'code_cerfa' => '75Z'],
                ],
            ],

            // ─── DÉPENSES ───────────────────────────────────────────────
            [
                'nom'  => 'Charges de personnel',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Salaires et traitements bruts',       'code_cerfa' => '641'],
                    ['nom' => 'Charges sociales patronales',         'code_cerfa' => '645'],
                    ['nom' => 'Indemnités bénévoles et remboursements', 'code_cerfa' => '647'],
                    ['nom' => 'Autres charges de personnel',         'code_cerfa' => '648'],
                ],
            ],
            [
                'nom'  => 'Charges de fonctionnement',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Loyer et charges locatives',          'code_cerfa' => '613'],
                    ['nom' => 'Eau, gaz, électricité',               'code_cerfa' => '606'],
                    ['nom' => 'Téléphone et internet',               'code_cerfa' => '626'],
                    ['nom' => 'Fournitures de bureau',               'code_cerfa' => '606B'],
                    ['nom' => 'Matériels et équipements',            'code_cerfa' => '615'],
                    ['nom' => 'Entretien et réparations',            'code_cerfa' => '615B'],
                    ['nom' => 'Assurances',                          'code_cerfa' => '616'],
                ],
            ],
            [
                'nom'  => 'Charges d\'activité',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Achats pour les activités',              'code_cerfa' => '601'],
                    ['nom' => 'Frais d\'événements et manifestations',  'code_cerfa' => '604'],
                    ['nom' => 'Frais de déplacement et transport',      'code_cerfa' => '625'],
                    ['nom' => 'Frais d\'hébergement et restauration',   'code_cerfa' => '625B'],
                    ['nom' => 'Prestations de services extérieurs',     'code_cerfa' => '611'],
                ],
            ],
            [
                'nom'  => 'Charges administratives',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Honoraires (comptable, avocat…)', 'code_cerfa' => '622'],
                    ['nom' => 'Frais bancaires',                 'code_cerfa' => '627'],
                    ['nom' => 'Communication et publicité',      'code_cerfa' => '623'],
                    ['nom' => 'Frais postaux',                   'code_cerfa' => '626B'],
                    ['nom' => 'Documentation et abonnements',    'code_cerfa' => '628'],
                    ['nom' => 'Impôts et taxes',                 'code_cerfa' => '63'],
                ],
            ],
            [
                'nom'  => 'Charges exceptionnelles',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Charges exceptionnelles diverses', 'code_cerfa' => '671'],
                    ['nom' => 'Dotations aux amortissements',     'code_cerfa' => '681'],
                ],
            ],
        ];

        foreach ($data as $item) {
            $categorie = Categorie::create([
                'nom'  => $item['nom'],
                'type' => $item['type'],
            ]);

            foreach ($item['sous'] as $sous) {
                $categorie->sousCategories()->create($sous);
            }
        }
    }
}
