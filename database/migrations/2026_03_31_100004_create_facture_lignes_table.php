<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facture_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignId('transaction_ligne_id')->nullable()->constrained('transaction_lignes')->nullOnDelete();
            $table->string('type')->default('montant');
            $table->string('libelle');
            $table->decimal('montant', 10, 2)->nullable();
            $table->integer('ordre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facture_lignes');
    }
};
