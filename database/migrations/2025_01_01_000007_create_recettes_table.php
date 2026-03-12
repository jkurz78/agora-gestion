<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recettes', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('libelle', 255);
            $table->decimal('montant_total', 10, 2);
            $table->string('mode_paiement', 20);
            $table->string('payeur', 150)->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('compte_id')->nullable()->constrained('comptes_bancaires');
            $table->boolean('pointe')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recettes');
    }
};
