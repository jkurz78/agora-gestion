<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $donateurs = DB::table('donateurs')->get();

        foreach ($donateurs as $donateur) {
            $tiersId = DB::table('tiers')->insertGetId([
                'type' => 'particulier',
                'nom' => $donateur->nom,
                'prenom' => $donateur->prenom,
                'email' => $donateur->email,
                'telephone' => null,
                'adresse' => $donateur->adresse ?? null,
                'pour_depenses' => false,
                'pour_recettes' => true,
                'created_at' => $donateur->created_at,
                'updated_at' => $donateur->updated_at,
            ]);

            DB::table('dons')
                ->where('donateur_id', $donateur->id)
                ->update(['tiers_id' => $tiersId]);
        }
    }

    public function down(): void
    {
        // Not reversible: tiers rows are new data
    }
};
