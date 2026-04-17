<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Association;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\TypeOperation;
use Illuminate\Database\Seeder;

class OperationsTiersSeeder extends Seeder
{
    public function run(): void
    {
        $associationId = Association::first()?->id ?? 1;

        // ── Opérations ────────────────────────────────────────────────────────
        $typePsa = TypeOperation::where('nom', 'Parcours de soins A')->first();
        $typeForm = TypeOperation::where('nom', 'Formation')->first();

        $operations = [
            ['association_id' => $associationId, 'nom' => 'Parcours 1', 'nombre_seances' => 30, 'statut' => 'en_cours', 'type_operation_id' => $typePsa?->id, 'date_debut' => '2025-09-15', 'date_fin' => '2026-06-30'],
            ['association_id' => $associationId, 'nom' => 'Parcours 2', 'nombre_seances' => 12, 'statut' => 'en_cours', 'type_operation_id' => $typeForm?->id, 'date_debut' => '2026-01-10', 'date_fin' => '2026-04-30'],
        ];

        foreach ($operations as $op) {
            Operation::firstOrCreate(['nom' => $op['nom']], $op);
        }

        // ── Tiers ─────────────────────────────────────────────────────────────
        $tiers = [
            // Particuliers
            ['association_id' => $associationId, 'type' => 'particulier', 'nom' => 'Tiers 1', 'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => true],
            ['association_id' => $associationId, 'type' => 'particulier', 'nom' => 'Tiers 2', 'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => true],
            ['association_id' => $associationId, 'type' => 'particulier', 'nom' => 'Tiers 3', 'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => true],
            // Entreprises
            ['association_id' => $associationId, 'type' => 'entreprise',  'nom' => 'FRS 1',   'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => false],
            ['association_id' => $associationId, 'type' => 'entreprise',  'nom' => 'FRS 2',   'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => false],
            ['association_id' => $associationId, 'type' => 'entreprise',  'nom' => 'FRS 3',   'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => false],
        ];

        foreach ($tiers as $t) {
            Tiers::firstOrCreate(['nom' => $t['nom'], 'type' => $t['type'], 'association_id' => $associationId], $t);
        }
    }
}
