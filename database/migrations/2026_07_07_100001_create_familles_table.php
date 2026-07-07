<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\FamillesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DC-1 du programme « dissolution sous_categories → comptes ».
 *
 * `familles` nomme un préfixe à 2 chiffres (classes 6/7 du PCG) — le
 * rattachement compte → famille reste DÉRIVÉ par préfixe (pas de FK).
 *
 * La donnée existante est migrée par FamillesSeeder::seed() :
 *  1. Chaque `categories.nom` au format "NN - Libellé" devient une famille
 *     (code=NN, nom=Libellé trim). Deux catégories parsant le même code pour
 *     la même association → la première gagne (unique par association+code).
 *  2. Chaque préfixe distinct des comptes de classe 6/7 sans famille encore
 *     nommée reçoit une famille de secours (nom = code).
 *
 * Extrait dans un service dédié (mirror AuditGuard/SystemeSeeder) pour être
 * rejouable depuis les tests sans dérouler la migration complète.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('familles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('code', 2);
            $table->string('nom');
            $table->timestamps();

            $table->unique(['association_id', 'code']);
        });

        FamillesSeeder::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('familles');
    }
};
