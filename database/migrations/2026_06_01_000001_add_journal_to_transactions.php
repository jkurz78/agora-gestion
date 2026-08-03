<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Slice 1 « journal de banque » — voir docs/specs/2026-06-01-journal-de-banque-slice1.md.
 *
 * Ajoute la colonne `journal` (ENUM) sur `transactions`. Nullable à ce stade :
 * le backfill (migration 2026_06_01_000002) la peuple puis la passe NOT NULL.
 * Les nouvelles lignes reçoivent leur journal via le hook Transaction::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('journal', ['vente', 'achat', 'banque', 'od'])
                ->nullable()
                ->after('type_ecriture');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('journal');
        });
    }
};
