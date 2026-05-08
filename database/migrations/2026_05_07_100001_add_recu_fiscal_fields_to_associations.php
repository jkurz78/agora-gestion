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
            $table->boolean('eligible_recu_fiscal')->default(false)->after('siret');
            $table->string('regime_fiscal_don')->nullable()->after('eligible_recu_fiscal');
            $table->text('objet_recu_fiscal')->nullable()->after('regime_fiscal_don');
            $table->string('rescrit_fiscal_numero')->nullable()->after('objet_recu_fiscal');
            $table->date('rescrit_fiscal_date')->nullable()->after('rescrit_fiscal_numero');
            $table->string('signataire_nom')->nullable()->after('rescrit_fiscal_date');
            $table->string('signataire_qualite')->nullable()->after('signataire_nom');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn([
                'eligible_recu_fiscal',
                'regime_fiscal_don',
                'objet_recu_fiscal',
                'rescrit_fiscal_numero',
                'rescrit_fiscal_date',
                'signataire_nom',
                'signataire_qualite',
            ]);
        });
    }
};
