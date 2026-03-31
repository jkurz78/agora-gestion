<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->string('siret')->nullable()->after('cachet_signature_path');
            $table->string('forme_juridique')->nullable()->default('Association loi 1901')->after('siret');
            $table->string('facture_conditions_reglement')->nullable()->after('forme_juridique');
            $table->text('facture_mentions_legales')->nullable()->after('facture_conditions_reglement');
            $table->text('facture_mentions_penalites')->nullable()->after('facture_mentions_legales');
            $table->foreignId('facture_compte_bancaire_id')->nullable()->constrained('comptes_bancaires')->after('facture_mentions_penalites');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropForeign(['facture_compte_bancaire_id']);
            $table->dropColumn([
                'siret',
                'forme_juridique',
                'facture_conditions_reglement',
                'facture_mentions_legales',
                'facture_mentions_penalites',
                'facture_compte_bancaire_id',
            ]);
        });
    }
};
