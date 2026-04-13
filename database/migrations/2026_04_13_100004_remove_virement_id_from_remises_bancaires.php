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
        // Soft-delete les VirementInternes liés à des remises (compte source = "Remises en banque")
        // Subquery compatible MySQL + SQLite ; DB::now() pour la date cross-DB
        DB::table('virements_internes')
            ->whereNull('deleted_at')
            ->whereIn('compte_source_id', function ($q): void {
                $q->select('id')
                    ->from('comptes_bancaires')
                    ->where('est_systeme', 1)
                    ->where('nom', 'Remises en banque');
            })
            ->update(['deleted_at' => now()]);

        // Retirer la FK et la colonne virement_id
        if (Schema::hasColumn('remises_bancaires', 'virement_id')) {
            if (DB::getDriverName() === 'sqlite') {
                // SQLite ne supporte pas dropForeign + dropColumn sur une colonne avec FK.
                // On reconstruit la table explicitement via Blueprint sans virement_id.
                Schema::create('remises_bancaires_new', function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedInteger('numero')->unique();
                    $table->date('date');
                    $table->string('mode_paiement');
                    $table->foreignId('compte_cible_id')->constrained('comptes_bancaires');
                    $table->string('libelle');
                    $table->foreignId('saisi_par')->constrained('users');
                    $table->timestamps();
                    $table->softDeletes();
                });

                $cols = '"id", "numero", "date", "mode_paiement", "compte_cible_id", "libelle", "saisi_par", "created_at", "updated_at", "deleted_at"';
                DB::statement("INSERT INTO remises_bancaires_new ({$cols}) SELECT {$cols} FROM remises_bancaires");
                Schema::drop('remises_bancaires');
                DB::statement('ALTER TABLE remises_bancaires_new RENAME TO remises_bancaires');
            } else {
                Schema::table('remises_bancaires', function (Blueprint $table): void {
                    $table->dropForeign(['virement_id']);
                    $table->dropColumn('virement_id');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('remises_bancaires', function (Blueprint $table): void {
            $table->unsignedBigInteger('virement_id')->nullable()->after('compte_cible_id');
            $table->foreign('virement_id')->references('id')->on('virements_internes');
        });
    }
};
