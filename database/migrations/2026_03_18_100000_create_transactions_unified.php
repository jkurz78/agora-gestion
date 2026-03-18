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
        // ── 1. Créer les nouvelles tables ─────────────────────────────────────
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->nullable();
            $table->string('old_type', 10)->nullable();
            $table->string('type', 10);
            $table->date('date');
            $table->string('libelle', 255)->nullable();
            $table->decimal('montant_total', 10, 2);
            $table->string('mode_paiement', 20);
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->foreignId('compte_id')->nullable()->constrained('comptes_bancaires')->nullOnDelete();
            $table->boolean('pointe')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rapprochement_id')->nullable()->constrained('rapprochements_bancaires')->nullOnDelete();
            $table->string('numero_piece', 20)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('transaction_lignes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->nullable();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('sous_categorie_id')->constrained('sous_categories');
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->integer('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->text('notes')->nullable();
            $table->softDeletes();
        });

        Schema::create('transaction_ligne_affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_ligne_id')->constrained('transaction_lignes')->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->unsignedInteger('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        // ── 2. Migration des données ──────────────────────────────────────────
        $isMySQL = DB::getDriverName() === 'mysql';
        if ($isMySQL) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            DB::statement("
                INSERT INTO transactions
                    (old_id, old_type, type, date, libelle, montant_total, mode_paiement,
                     tiers_id, reference, compte_id, pointe, notes, saisi_par,
                     rapprochement_id, numero_piece, deleted_at, created_at, updated_at)
                SELECT id, 'depense', 'depense', date, libelle, montant_total, mode_paiement,
                       tiers_id, reference, compte_id, pointe, notes, saisi_par,
                       rapprochement_id, numero_piece, deleted_at, created_at, updated_at
                FROM depenses
            ");

            DB::statement("
                INSERT INTO transactions
                    (old_id, old_type, type, date, libelle, montant_total, mode_paiement,
                     tiers_id, reference, compte_id, pointe, notes, saisi_par,
                     rapprochement_id, numero_piece, deleted_at, created_at, updated_at)
                SELECT id, 'recette', 'recette', date, libelle, montant_total, mode_paiement,
                       tiers_id, reference, compte_id, pointe, notes, saisi_par,
                       rapprochement_id, numero_piece, deleted_at, created_at, updated_at
                FROM recettes
            ");

            DB::statement("
                INSERT INTO transaction_lignes
                    (old_id, transaction_id, sous_categorie_id, operation_id, seance, montant, notes, deleted_at)
                SELECT dl.id, t.id, dl.sous_categorie_id, dl.operation_id, dl.seance, dl.montant, dl.notes, dl.deleted_at
                FROM depense_lignes dl
                JOIN transactions t ON t.old_id = dl.depense_id AND t.old_type = 'depense'
            ");

            DB::statement("
                INSERT INTO transaction_lignes
                    (old_id, transaction_id, sous_categorie_id, operation_id, seance, montant, notes, deleted_at)
                SELECT rl.id, t.id, rl.sous_categorie_id, rl.operation_id, rl.seance, rl.montant, rl.notes, rl.deleted_at
                FROM recette_lignes rl
                JOIN transactions t ON t.old_id = rl.recette_id AND t.old_type = 'recette'
            ");

            DB::statement("
                INSERT INTO transaction_ligne_affectations
                    (transaction_ligne_id, operation_id, seance, montant, notes, created_at, updated_at)
                SELECT tl.id, dla.operation_id, dla.seance, dla.montant, dla.notes, dla.created_at, dla.updated_at
                FROM depense_ligne_affectations dla
                JOIN transaction_lignes tl ON tl.old_id = dla.depense_ligne_id
                JOIN transactions t ON t.id = tl.transaction_id AND t.old_type = 'depense'
            ");

            DB::statement("
                INSERT INTO transaction_ligne_affectations
                    (transaction_ligne_id, operation_id, seance, montant, notes, created_at, updated_at)
                SELECT tl.id, rla.operation_id, rla.seance, rla.montant, rla.notes, rla.created_at, rla.updated_at
                FROM recette_ligne_affectations rla
                JOIN transaction_lignes tl ON tl.old_id = rla.recette_ligne_id
                JOIN transactions t ON t.id = tl.transaction_id AND t.old_type = 'recette'
            ");

            // ── 3. Supprimer les colonnes temporaires ─────────────────────────────
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn(['old_id', 'old_type']);
            });

            Schema::table('transaction_lignes', function (Blueprint $table) {
                $table->dropColumn('old_id');
            });

            // Les anciennes tables sont conservées intentionnellement comme filet de sécurité.
            // Elles seront supprimées par la Migration B après validation en production.
        } finally {
            if ($isMySQL) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    public function down(): void
    {
        // ATTENTION : supprime uniquement les nouvelles tables (les tables legacy depenses/recettes
        // sont conservées par la Task 3 et ne sont pas restaurées ici).
        // En production, exécuter cette migration rollback détruirait les données migrées sans
        // restaurer les tables legacy — utiliser la migration de Task 13 pour un rollback production.
        Schema::dropIfExists('transaction_ligne_affectations');
        Schema::dropIfExists('transaction_lignes');
        Schema::dropIfExists('transactions');
    }
};
