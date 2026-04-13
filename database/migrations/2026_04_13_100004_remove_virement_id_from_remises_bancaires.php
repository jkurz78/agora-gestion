<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Soft-delete les VirementInternes liés à des remises (compte source = "Remises en banque")
        \Illuminate\Support\Facades\DB::statement("
            UPDATE virements_internes vi
            JOIN comptes_bancaires cs ON cs.id = vi.compte_source_id
            SET vi.deleted_at = NOW()
            WHERE cs.est_systeme = 1 AND cs.nom = 'Remises en banque'
              AND vi.deleted_at IS NULL
        ");

        // Retirer la FK et la colonne virement_id
        Schema::table('remises_bancaires', function (Blueprint $table): void {
            $table->dropForeign(['virement_id']);
            $table->dropColumn('virement_id');
        });
    }

    public function down(): void
    {
        Schema::table('remises_bancaires', function (Blueprint $table): void {
            $table->unsignedBigInteger('virement_id')->nullable()->after('compte_cible_id');
            $table->foreign('virement_id')->references('id')->on('virements_internes');
        });
    }
};
