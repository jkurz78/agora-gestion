<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('immobilisations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('numero', 10);
            $table->string('libelle', 255);
            $table->unsignedInteger('quantite')->default(1);
            $table->foreignId('compte_id')->constrained('comptes');
            $table->foreignId('compte_amortissement_id')->constrained('comptes');
            $table->decimal('montant_acquisition', 10, 2);
            $table->date('date_mise_en_service');
            $table->unsignedSmallInteger('duree_mois');
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['association_id', 'numero']);
            $table->index('transaction_id');
        });

        Schema::create('immobilisation_dotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('immobilisation_id')->constrained('immobilisations')->cascadeOnDelete();
            $table->unsignedSmallInteger('exercice');
            $table->decimal('montant', 10, 2);
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->timestamps();

            $table->unique(['immobilisation_id', 'exercice']);
            $table->index('transaction_id');
        });

        Schema::create('immobilisation_sequences', function (Blueprint $table): void {
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->unsignedBigInteger('dernier_numero')->default(0);

            $table->primary('association_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immobilisation_sequences');
        Schema::dropIfExists('immobilisation_dotations');
        Schema::dropIfExists('immobilisations');
    }
};
