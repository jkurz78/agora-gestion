<?php

declare(strict_types=1);

use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a_nouveau_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->smallInteger('exercice_source');
            $table->smallInteger('exercice_cible');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('origine', 30)->default(OrigineANouveau::Cloture->value);
            $table->string('statut', 20)->default(StatutANouveau::Active->value);
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invalidee_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invalidee_at')->nullable();
            $table->text('motif_invalidation')->nullable();
            $table->timestamps();

            $table->index(['association_id', 'exercice_source', 'statut'], 'an_gen_source_statut_idx');
            $table->index(['association_id', 'exercice_cible', 'statut'], 'an_gen_cible_statut_idx');
        });

        Schema::create('a_nouveau_ligne_origines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('generation_id')->constrained('a_nouveau_generations')->restrictOnDelete();
            $table->foreignId('ligne_an_id')->constrained('transaction_lignes')->restrictOnDelete();
            $table->foreignId('ligne_source_id')->constrained('transaction_lignes')->restrictOnDelete();
            $table->foreignId('ligne_racine_id')->constrained('transaction_lignes')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['generation_id', 'ligne_an_id'], 'an_origine_generation_ligne_unique');
            $table->index(['association_id', 'ligne_racine_id'], 'an_origine_racine_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a_nouveau_ligne_origines');
        Schema::dropIfExists('a_nouveau_generations');
    }
};
