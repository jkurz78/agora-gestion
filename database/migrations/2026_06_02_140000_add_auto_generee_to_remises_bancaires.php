<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remises_bancaires', function (Blueprint $table): void {
            // Rendre numero nullable : les remises auto-générées n'ont pas de numéro RBC.
            // Pas de ->after() : non portable sqlite.
            $table->unsignedInteger('numero')->nullable()->change();
            // Flag auto-remise (créée par le rapprochement, sans saisie manuelle).
            $table->boolean('auto_generee')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('remises_bancaires', function (Blueprint $table): void {
            $table->dropColumn('auto_generee');
            $table->unsignedInteger('numero')->nullable(false)->change();
        });
    }
};
