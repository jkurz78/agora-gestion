<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\AuditGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Creates the new `comptes` table that unifies what `sous_categories`
 * (classes 6 & 7) and `comptes_bancaires` (classe 5) carried separately
 * before the partie double refoundation. System accounts (411/401/5112/530)
 * land in Step 5.
 *
 * Pre-check: if any sous_categorie has NULL code_cerfa, abort with a
 * RuntimeException pointing to the audit:compta-v5-preparation command
 * introduced in Step 2.
 *
 * Schema: per docs/specs/2026-05-19-fondations-partie-double-slice1.md §2.1
 *  - numero_pcg unique per tenant
 *  - classe denormalized from the first digit of numero_pcg
 *  - lettrable flag (TRUE for 411/401/5112/530 in later steps)
 *  - bank attributes nullable (populated in Step 4)
 *  - soft deletes
 *
 * Seed: for each sous_categorie with code_cerfa NOT NULL, insert a compte
 * with classe derived from the first character of code_cerfa, categorie_id
 * carried over, and pour_inscriptions derived from `usages_sous_categories`.
 */
return new class extends Migration
{
    public function up(): void
    {
        AuditGuard::assertAuditPassed();

        Schema::create('comptes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association');
            $table->string('numero_pcg', 10);
            $table->string('intitule', 255);
            $table->unsignedTinyInteger('classe');
            $table->foreignId('categorie_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('parent_compte_id')->nullable()->constrained('comptes')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->boolean('est_systeme')->default(false);
            $table->boolean('pour_inscriptions')->default(false);
            $table->boolean('lettrable')->default(false);
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('domiciliation', 255)->nullable();
            $table->decimal('solde_initial', 12, 2)->nullable();
            $table->date('date_solde_initial')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['association_id', 'numero_pcg'], 'comptes_asso_numero_pcg_unique');
            $table->index(['association_id', 'classe'], 'comptes_asso_classe_idx');
            $table->index(['association_id', 'lettrable'], 'comptes_asso_lettrable_idx');
        });

        DB::statement(AuditGuard::seedFromSousCategoriesSql());
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes');
    }
};
