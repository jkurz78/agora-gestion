<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donateur_id')->nullable()->constrained('donateurs')->nullOnDelete();
            $table->date('date');
            $table->decimal('montant', 10, 2);
            $table->string('mode_paiement', 20);
            $table->string('objet', 255)->nullable();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->integer('seance')->nullable();
            $table->foreignId('compte_id')->nullable()->constrained('comptes_bancaires');
            $table->boolean('pointe')->default(false);
            $table->boolean('recu_emis')->default(false);
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dons');
    }
};
