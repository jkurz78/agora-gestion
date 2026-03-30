<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SousCategorie;
use App\Models\TypeOperation;
use Illuminate\Database\Seeder;

class TypeOperationSeeder extends Seeder
{
    public function run(): void
    {
        $sousParcours = SousCategorie::where('nom', 'Parcours thérapeutiques')->firstOrFail();
        $sousFormation = SousCategorie::where('nom', 'Formations')->firstOrFail();

        // ── PSA — Parcours de soins A ───────────────────────────────────
        $psa = TypeOperation::firstOrCreate(
            ['code' => 'PSA'],
            [
                'nom' => 'Parcours de soins A',
                'description' => 'Parcours thérapeutique de 30 séances avec médiation animale.',
                'sous_categorie_id' => $sousParcours->id,
                'nombre_seances' => 30,
                'formulaire_actif' => true,
                'formulaire_prescripteur' => true,
                'formulaire_parcours_therapeutique' => true,
                'formulaire_droit_image' => true,
                'reserve_adherents' => true,
                'actif' => true,
            ],
        );

        $psa->tarifs()->delete();
        $psa->tarifs()->createMany([
            ['libelle' => 'Plein tarif', 'montant' => 350.00],
            ['libelle' => 'Tarif réduit', 'montant' => 250.00],
            ['libelle' => 'Tarif solidaire', 'montant' => 150.00],
        ]);

        // ── FORM — Formation ────────────────────────────────────────────
        $form = TypeOperation::firstOrCreate(
            ['code' => 'FORM'],
            [
                'nom' => 'Formation',
                'description' => 'Formation ouverte à tous, 12 séances.',
                'sous_categorie_id' => $sousFormation->id,
                'nombre_seances' => 12,
                'formulaire_actif' => false,
                'formulaire_parcours_therapeutique' => false,
                'reserve_adherents' => false,
                'actif' => true,
            ],
        );

        $form->tarifs()->delete();
        $form->tarifs()->createMany([
            ['libelle' => 'Normal', 'montant' => 180.00],
            ['libelle' => 'Réduit', 'montant' => 120.00],
        ]);
    }
}
