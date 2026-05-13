<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encadrement_previsions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->foreignId('tiers_id')->constrained('tiers')->cascadeOnDelete();
            $table->foreignId('sous_categorie_id')->constrained('sous_categories')->restrictOnDelete();
            $table->foreignId('seance_id')->constrained('seances')->cascadeOnDelete();
            $table->decimal('montant_prevu', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(
                ['operation_id', 'tiers_id', 'sous_categorie_id', 'seance_id'],
                'encadrement_previsions_unique'
            );
            $table->index(['operation_id', 'tiers_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encadrement_previsions');
    }
};
