<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modèle Anthropic choisi par l'association pour l'OCR des factures.
 * Sélectionné dans les Paramètres parmi les modèles réellement disponibles
 * (GET /v1/models) — null = repli sur le défaut applicatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->string('invoice_ocr_model')->nullable()->after('anthropic_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn('invoice_ocr_model');
        });
    }
};
