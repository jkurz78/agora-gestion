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
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->decimal('montant_facial', 10, 2)->nullable()->after('notes');
            $table->boolean('deductible_fiscal')->nullable()->after('montant_facial');
            $table->enum('mode', ['exercice', 'duree', 'illimite'])->nullable()->after('deductible_fiscal');
            $table->unsignedSmallInteger('duree_mois')->nullable()->after('mode');
            $table->string('label_formule', 120)->nullable()->after('duree_mois');
        });

        // Backfill MySQL-only (SQLite ne supporte pas UPDATE ... INNER JOIN avec alias)
        if (DB::getDriverName() === 'mysql') {
            // Backfill : pour chaque adhésion existante, snapshoter depuis sa formule
            DB::statement('
                UPDATE adhesions a
                INNER JOIN formules_adhesion f ON f.id = a.formule_adhesion_id
                SET
                    a.montant_facial = COALESCE((SELECT SUM(montant) FROM transaction_lignes WHERE transaction_id = a.transaction_id), 0),
                    a.deductible_fiscal = f.deductible_fiscal,
                    a.mode = f.mode,
                    a.duree_mois = f.duree_mois,
                    a.label_formule = f.nom
                WHERE a.formule_adhesion_id IS NOT NULL
            ');

            // Pour les adhésions sans formule (legacy), poser des valeurs neutres
            DB::statement("
                UPDATE adhesions
                SET
                    montant_facial = COALESCE((SELECT SUM(montant) FROM transaction_lignes WHERE transaction_id = adhesions.transaction_id), 0),
                    deductible_fiscal = false,
                    mode = 'exercice',
                    label_formule = 'Adhésion legacy'
                WHERE formule_adhesion_id IS NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->dropColumn(['montant_facial', 'deductible_fiscal', 'mode', 'duree_mois', 'label_formule']);
        });
    }
};
