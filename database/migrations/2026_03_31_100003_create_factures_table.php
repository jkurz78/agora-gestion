<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique()->nullable();
            $table->date('date');
            $table->string('statut')->default('brouillon');
            $table->foreignId('tiers_id')->constrained('tiers');
            $table->foreignId('compte_bancaire_id')->nullable()->constrained('comptes_bancaires');
            $table->string('conditions_reglement')->nullable();
            $table->text('mentions_legales')->nullable();
            $table->decimal('montant_total', 10, 2)->default(0);
            $table->string('numero_avoir')->unique()->nullable();
            $table->date('date_annulation')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('saisi_par')->constrained('users');
            $table->integer('exercice');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
    }
};
