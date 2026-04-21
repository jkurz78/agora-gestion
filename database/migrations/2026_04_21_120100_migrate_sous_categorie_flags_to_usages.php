<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'pour_dons' => 'don',
            'pour_cotisations' => 'cotisation',
            'pour_inscriptions' => 'inscription',
            'pour_frais_kilometriques' => 'frais_kilometriques',
        ];

        foreach ($map as $column => $usage) {
            DB::table('sous_categories')
                ->where($column, true)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($usage) {
                    $now = now();
                    $inserts = [];
                    foreach ($rows as $r) {
                        $inserts[] = [
                            'association_id' => $r->association_id,
                            'sous_categorie_id' => $r->id,
                            'usage' => $usage,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($inserts !== []) {
                        DB::table('usages_sous_categories')->upsert(
                            $inserts,
                            ['association_id', 'sous_categorie_id', 'usage'],
                            ['updated_at']
                        );
                    }
                });
        }
    }

    public function down(): void
    {
        DB::table('usages_sous_categories')->whereIn('usage', [
            'don', 'cotisation', 'inscription', 'frais_kilometriques',
        ])->delete();
    }
};
