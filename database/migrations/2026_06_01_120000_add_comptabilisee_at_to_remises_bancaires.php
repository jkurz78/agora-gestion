<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remises_bancaires', function (Blueprint $table): void {
            // Pas de ->after() : non portable sqlite (les tests migrent sur sqlite :memory:).
            $table->timestamp('comptabilisee_at')->nullable();
        });

        // Backfill PORTABLE (sqlite + mysql) : une remise est « comptabilisée » ssi
        // elle porte une T4 (ligne 512X au débit). Date de la remise = proxy historique.
        // Sur table vide (migration de test) la jointure ne renvoie rien : no-op.
        $remises = DB::table('remises_bancaires as r')
            ->join('transactions as t', 't.remise_id', '=', 'r.id')
            ->join('transaction_lignes as tl', 'tl.transaction_id', '=', 't.id')
            ->join('comptes as c', 'c.id', '=', 'tl.compte_id')
            ->whereNull('r.comptabilisee_at')
            ->whereNull('t.deleted_at')
            ->where('c.numero_pcg', 'like', '512%')
            ->where('c.numero_pcg', 'not like', '5112%')
            ->where('tl.debit', '>', 0)
            ->select('r.id', 'r.date')
            ->distinct()
            ->get();

        foreach ($remises as $r) {
            DB::table('remises_bancaires')->where('id', $r->id)
                ->update(['comptabilisee_at' => $r->date]);
        }
    }

    public function down(): void
    {
        Schema::table('remises_bancaires', function (Blueprint $table): void {
            $table->dropColumn('comptabilisee_at');
        });
    }
};
