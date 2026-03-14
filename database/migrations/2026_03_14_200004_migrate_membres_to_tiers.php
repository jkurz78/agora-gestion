<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $membres = DB::table('membres')->get();

            foreach ($membres as $membre) {
                $tiersId = DB::table('tiers')->insertGetId([
                    'type' => 'particulier',
                    'nom' => $membre->nom,
                    'prenom' => $membre->prenom,
                    'email' => $membre->email,
                    'telephone' => $membre->telephone ?? null,
                    'adresse' => $membre->adresse ?? null,
                    'date_adhesion' => $membre->date_adhesion ?? null,
                    'statut_membre' => 'actif',
                    'notes_membre' => $membre->notes ?? null,
                    'pour_depenses' => false,
                    'pour_recettes' => false,
                    'created_at' => $membre->created_at,
                    'updated_at' => $membre->updated_at,
                ]);

                DB::table('cotisations')
                    ->where('membre_id', $membre->id)
                    ->update(['tiers_id' => $tiersId]);
            }
        });
    }

    public function down(): void
    {
        // Not reversible: tiers rows are new data
    }
};
