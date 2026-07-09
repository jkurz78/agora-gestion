<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DC-9 du programme « dissolution sous_categories → comptes ».
 *
 * Provisionne le plan comptable de dev/staging (migrate:fresh --seed) en
 * créant `Famille` + `Compte` comme objets PRIMAIRES — le miroir
 * `CompteObserver` matérialise automatiquement `SousCategorie`/`Categorie`
 * pour le pont CR legacy (tables encore lues tant que DC-10 ne les a pas
 * dropées).
 *
 * Anciennement `CategoriesSeeder` (renommé DC-9 — créait Categorie/SousCategorie
 * en primaire avant la bascule).
 */
class PlanComptableSeeder extends Seeder
{
    public function run(): void
    {
        $driver = DB::connection()->getDriverName();
        $disableFk = $driver === 'sqlite' ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS=0';
        $enableFk = $driver === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS=1';

        DB::statement($disableFk);
        UsageSousCategorie::truncate();
        SousCategorie::truncate();
        Categorie::truncate();
        Compte::truncate();
        Famille::truncate();
        DB::statement($enableFk);

        $data = [
            // ─── RECETTES ───────────────────────────────────────────────
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
                    ['intitule' => 'Cotisations',  'numero_pcg' => '751', 'usages' => [UsageComptable::Cotisation]],
                    ['intitule' => 'Dons manuels', 'numero_pcg' => '754', 'usages' => [UsageComptable::Don]],
                    ['intitule' => 'Mécénat',      'numero_pcg' => '756', 'usages' => [UsageComptable::Don]],
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

            // ─── DÉPENSES ───────────────────────────────────────────────
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
                    ['intitule' => 'Location salle',                  'numero_pcg' => '613A'],
                    ['intitule' => 'Location lieu (centre équestre)', 'numero_pcg' => '613B'],
                    ['intitule' => 'Location lieu (salle d\'armes)',  'numero_pcg' => '613C'],
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
                    // pas 66 (voir DefaultChartOfAccountsService pour le détail
                    // du rattachement famille dérivé par préfixe, pas par FK).
                    ['intitule' => 'Frais bancaires', 'numero_pcg' => '627'],
                ],
            ],
            [
                'code' => '67',
                'nom' => 'Charges exceptionnelles',
                'comptes' => [],
            ],
        ];

        $associationId = Association::first()?->id ?? 1;

        foreach ($data as $item) {
            Famille::create([
                'association_id' => $associationId,
                'code' => $item['code'],
                'nom' => $item['nom'],
            ]);

            foreach ($item['comptes'] as $c) {
                $usages = $c['usages'] ?? [];
                unset($c['usages']);

                $compte = Compte::create(array_merge([
                    'association_id' => $associationId,
                    'classe' => (int) $item['code'][0],
                    'actif' => true,
                    'est_systeme' => false,
                    'pour_inscriptions' => false,
                    'lettrable' => false,
                ], $c));

                foreach ($usages as $usage) {
                    UsageSousCategorie::create([
                        'association_id' => $associationId,
                        'compte_id' => $compte->id,
                        'usage' => $usage->value,
                    ]);
                }
            }
        }
    }
}
