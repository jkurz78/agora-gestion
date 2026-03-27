<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Operation;
use App\Models\Tiers;
use App\Models\TypeOperation;
use Illuminate\Database\Seeder;

class OperationsTiersSeeder extends Seeder
{
    public function run(): void
    {
        // ── Opérations ────────────────────────────────────────────────────────
        $typePsa = TypeOperation::where('code', 'PSA')->first();
        $typeForm = TypeOperation::where('code', 'FORM')->first();

        $operations = [
            ['nom' => 'Parcours 1', 'nombre_seances' => 30, 'statut' => 'en_cours', 'type_operation_id' => $typePsa?->id],
            ['nom' => 'Parcours 2', 'nombre_seances' => 12, 'statut' => 'en_cours', 'type_operation_id' => $typeForm?->id],
        ];

        foreach ($operations as $op) {
            Operation::firstOrCreate(['nom' => $op['nom']], $op);
        }

        // ── Tiers ─────────────────────────────────────────────────────────────
        $tiers = [
            // Particuliers
            ['type' => 'particulier', 'nom' => 'Tiers 1', 'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => true],
            ['type' => 'particulier', 'nom' => 'Tiers 2', 'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => true],
            ['type' => 'particulier', 'nom' => 'Tiers 3', 'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => true],
            // Entreprises
            ['type' => 'entreprise',  'nom' => 'FRS 1',   'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => false],
            ['type' => 'entreprise',  'nom' => 'FRS 2',   'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => false],
            ['type' => 'entreprise',  'nom' => 'FRS 3',   'prenom' => null, 'email' => null, 'telephone' => null, 'adresse_ligne1' => null, 'pour_depenses' => true,  'pour_recettes' => false],
        ];

        foreach ($tiers as $t) {
            Tiers::firstOrCreate(['nom' => $t['nom'], 'type' => $t['type']], $t);
        }
    }
}
