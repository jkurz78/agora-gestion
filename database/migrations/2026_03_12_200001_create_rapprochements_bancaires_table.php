<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapprochements_bancaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compte_id')->constrained('comptes_bancaires');
            $table->date('date_fin');
            $table->decimal('solde_ouverture', 10, 2);
            $table->decimal('solde_fin', 10, 2);
            $table->string('statut', 20)->default('en_cours'); // 'en_cours' | 'verrouille'
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamp('verrouille_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapprochements_bancaires');
    }
};
