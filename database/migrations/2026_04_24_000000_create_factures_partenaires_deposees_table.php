<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures_partenaires_deposees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association');
            $table->foreignId('tiers_id')->constrained('tiers');
            $table->date('date_facture');
            $table->string('numero_facture', 50);
            $table->string('pdf_path');
            $table->unsignedInteger('pdf_taille');
            $table->string('statut', 20)->default('soumise');
            $table->text('motif_rejet')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamp('traitee_at')->nullable();
            $table->timestamps();

            $table->index(['association_id', 'tiers_id', 'statut']);
            $table->index(['association_id', 'statut', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures_partenaires_deposees');
    }
};
