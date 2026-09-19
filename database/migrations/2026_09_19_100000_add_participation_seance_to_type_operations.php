<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_operations', function (Blueprint $table): void {
            // Participation optionnelle collectée à chaque séance (oui / non par
            // participant, stockée dans presences.kine) et son libellé affiché
            // (« Kiné » historiquement). Indépendante du parcours thérapeutique.
            $table->boolean('participation_seance_active')->default(false)->after('formulaire_qualificatif_atelier');
            $table->string('participation_seance_libelle', 12)->nullable()->after('participation_seance_active');
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table): void {
            $table->dropColumn(['participation_seance_active', 'participation_seance_libelle']);
        });
    }
};
