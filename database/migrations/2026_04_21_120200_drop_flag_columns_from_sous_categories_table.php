<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->dropColumn([
                'pour_dons',
                'pour_cotisations',
                'pour_inscriptions',
                'pour_frais_kilometriques',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->boolean('pour_dons')->default(false);
            $table->boolean('pour_cotisations')->default(false);
            $table->boolean('pour_inscriptions')->default(false);
            $table->boolean('pour_frais_kilometriques')->default(false);
        });

        // Rejouer les flags depuis la pivot
        $map = [
            'don' => 'pour_dons',
            'cotisation' => 'pour_cotisations',
            'inscription' => 'pour_inscriptions',
            'frais_kilometriques' => 'pour_frais_kilometriques',
        ];
        foreach ($map as $usage => $column) {
            $ids = DB::table('usages_sous_categories')->where('usage', $usage)->pluck('sous_categorie_id');
            if ($ids->isNotEmpty()) {
                DB::table('sous_categories')->whereIn('id', $ids)->update([$column => true]);
            }
        }
    }
};
