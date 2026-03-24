<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->foreignId('compte_helloasso_id')->nullable()->constrained('comptes_bancaires')->nullOnDelete()->after('environnement');
            $table->foreignId('compte_versement_id')->nullable()->constrained('comptes_bancaires')->nullOnDelete()->after('compte_helloasso_id');
            $table->foreignId('sous_categorie_don_id')->nullable()->constrained('sous_categories')->nullOnDelete()->after('compte_versement_id');
            $table->foreignId('sous_categorie_cotisation_id')->nullable()->constrained('sous_categories')->nullOnDelete()->after('sous_categorie_don_id');
            $table->foreignId('sous_categorie_inscription_id')->nullable()->constrained('sous_categories')->nullOnDelete()->after('sous_categorie_cotisation_id');
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->dropForeign(['compte_helloasso_id']);
            $table->dropForeign(['compte_versement_id']);
            $table->dropForeign(['sous_categorie_don_id']);
            $table->dropForeign(['sous_categorie_cotisation_id']);
            $table->dropForeign(['sous_categorie_inscription_id']);
            $table->dropColumn([
                'compte_helloasso_id', 'compte_versement_id',
                'sous_categorie_don_id', 'sous_categorie_cotisation_id', 'sous_categorie_inscription_id',
            ]);
        });
    }
};
