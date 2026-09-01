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
        $this->refuserSiDoublons();

        Schema::table('budget_lines', function (Blueprint $table): void {
            $table->foreignId('operation_id')
                ->nullable()
                ->after('compte_id')
                ->constrained('operations')
                ->nullOnDelete();

            // COALESCE(operation_id, 0) : sans elle, un index UNIQUE laisserait
            // passer deux enveloppes sur le même compte, MySQL comme SQLite
            // considérant deux NULL comme distincts.
            //
            // VIRTUAL et non STORED : SQLite refuse d'ajouter une colonne générée
            // STORED par ALTER TABLE. Les deux moteurs indexent le virtuel.
            $table->unsignedBigInteger('operation_key')
                ->virtualAs('COALESCE(operation_id, 0)');

            $table->unique(
                ['association_id', 'exercice', 'compte_id', 'operation_key'],
                'budget_lines_asso_exercice_compte_operation_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table): void {
            $table->dropUnique('budget_lines_asso_exercice_compte_operation_unique');
            $table->dropColumn('operation_key');
            $table->dropConstrainedForeignId('operation_id');
        });
    }

    /**
     * La table n'a jamais porté d'unicité : des doublons peuvent exister en
     * production. Les laisser atteindre le CREATE UNIQUE INDEX produirait une
     * erreur moteur illisible en pleine migration — on refuse avant, en nommant
     * les lignes fautives.
     */
    private function refuserSiDoublons(): void
    {
        $doublons = DB::table('budget_lines')
            ->select('association_id', 'exercice', 'compte_id', DB::raw('COUNT(*) as n'))
            ->groupBy('association_id', 'exercice', 'compte_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($doublons->isEmpty()) {
            return;
        }

        $details = $doublons
            ->map(fn ($d): string => "association {$d->association_id}, exercice {$d->exercice}, compte {$d->compte_id} : {$d->n} lignes")
            ->implode(' ; ');

        throw new RuntimeException(
            'Impossible de poser l\'unicité sur budget_lines : des doublons existent. '.
            'Corriger ces lignes puis relancer la migration. '.$details
        );
    }
};
