<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recus_fiscaux_emis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('numero');
            $table->smallInteger('annee_civile');
            $table->foreignId('tiers_id')->constrained('tiers');
            $table->foreignId('transaction_ligne_id')->nullable()->constrained('transaction_lignes');
            $table->integer('montant_centimes');
            $table->date('date_versement');
            $table->string('mode_versement');
            $table->string('forme_don');
            $table->string('article_cgi');
            $table->string('pdf_path');
            $table->string('pdf_hash', 64);
            $table->timestamp('emitted_at');
            $table->foreignId('emitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annule_at')->nullable();
            $table->text('annule_motif')->nullable();
            $table->foreignId('remplace_par_id')->nullable()->constrained('recus_fiscaux_emis')->nullOnDelete();
            $table->timestamps();

            $table->unique(['association_id', 'numero']);
            $table->index(['association_id', 'tiers_id', 'annee_civile']);
            $table->index(['association_id', 'transaction_ligne_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recus_fiscaux_emis');
    }
};
