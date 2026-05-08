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
        Schema::table('association', function (Blueprint $table) {
            // regime_fiscal_don reste en string — la validation enum est côté application.
            // On ajoute uniquement les deux nouveaux booléens.
            $table->boolean('loi_coluche_eligible')->default(false)->after('regime_fiscal_don');
            $table->boolean('ifi_eligible')->default(false)->after('loi_coluche_eligible');
        });

        // Best-effort mapping des valeurs texte existantes vers les valeurs enum
        DB::table('association')->where('regime_fiscal_don', 'RUP')->update(['regime_fiscal_don' => 'reconnue_utilite_publique']);
        DB::table('association')->whereRaw('LOWER(regime_fiscal_don) LIKE ?', ['%int_r_t g_n_ral%'])->update(['regime_fiscal_don' => 'interet_general']);
        DB::table('association')->whereRaw('LOWER(regime_fiscal_don) LIKE ?', ['%interet general%'])->update(['regime_fiscal_don' => 'interet_general']);
        DB::table('association')->whereRaw('regime_fiscal_don LIKE ?', ['%intérêt général%'])->update(['regime_fiscal_don' => 'interet_general']);
        DB::table('association')->whereRaw('LOWER(regime_fiscal_don) LIKE ?', ['%cultuel%'])->update(['regime_fiscal_don' => 'cultuelle']);
        DB::table('association')->whereRaw('LOWER(regime_fiscal_don) LIKE ?', ['%utilite publique%'])->update(['regime_fiscal_don' => 'reconnue_utilite_publique']);
        DB::table('association')->whereRaw('regime_fiscal_don LIKE ?', ['%utilité publique%'])->update(['regime_fiscal_don' => 'reconnue_utilite_publique']);
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn(['loi_coluche_eligible', 'ifi_eligible']);
        });
    }
};
