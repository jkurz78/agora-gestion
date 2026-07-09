<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DC-8 (dissolution sous_categories → comptes) : les écrans écrivent désormais
 * compte_id ; sous_categorie_id n'est plus rempli que par le miroir transitoire
 * (trait SyncCompteDepuisSousCategorie), qui peut légitimement rester vide
 * (compte système sans miroir, ex. 681/781). Les 6 tables encore NOT NULL
 * passent nullable — la colonne disparaît en DC-10.
 */
return new class extends Migration
{
    private const TABLES = [
        'budget_lines',
        'encadrement_previsions',
        'formules_adhesion',
        'provisions',
        'type_operations',
        'usages_sous_categories',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('sous_categorie_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('sous_categorie_id')->nullable(false)->change();
            });
        }
    }
};
