<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devis_lignes', function (Blueprint $table): void {
            // Type de ligne : montant (ligne chiffrée) ou texte (ligne commentaire)
            $table->string('type', 10)->default('montant')->after('ordre');

            // Champs nullables pour les lignes de type texte
            $table->decimal('prix_unitaire', 12, 2)->nullable()->change();
            $table->decimal('quantite', 10, 3)->nullable()->change();
            $table->decimal('montant', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('devis_lignes', function (Blueprint $table): void {
            $table->dropColumn('type');

            // Restore NOT NULL constraints (existing rows all have values)
            $table->decimal('prix_unitaire', 12, 2)->nullable(false)->change();
            $table->decimal('quantite', 10, 3)->nullable(false)->change();
            $table->decimal('montant', 12, 2)->nullable(false)->change();
        });
    }
};
