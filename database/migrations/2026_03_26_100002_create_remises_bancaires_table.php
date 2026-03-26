<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remises_bancaires', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('numero')->unique();
            $table->date('date');
            $table->string('mode_paiement');
            $table->foreignId('compte_cible_id')->constrained('comptes_bancaires');
            $table->foreignId('virement_id')->nullable()->constrained('virements_internes')->nullOnDelete();
            $table->string('libelle');
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remises_bancaires');
    }
};
