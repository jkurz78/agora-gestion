<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tables = [
        'tiers', 'categories', 'sous_categories',
        'transactions', 'comptes_bancaires', 'remises_bancaires',
        'rapprochements_bancaires', 'virements_internes',
        'operations', 'type_operations', 'participants', 'seances',
        'factures', 'documents_previsionnels', 'budget_lines',
        'exercices', 'provisions',
        'email_templates', 'message_templates', 'campagnes_email',
        'formulaire_tokens',
    ];

    public function up(): void
    {
        // If no association exists, create a default one (fresh install case)
        $first = DB::table('association')->first();
        if (! $first) {
            DB::table('association')->insert([
                'nom' => 'Association par défaut',
                'slug' => 'defaut',
                'exercice_mois_debut' => 9,
                'statut' => 'actif',
                'wizard_completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $first = DB::table('association')->first();
        }

        $firstId = $first->id;

        foreach ($this->tables as $table) {
            DB::table($table)->whereNull('association_id')->update(['association_id' => $firstId]);
        }
    }

    public function down(): void
    {
        // No-op: we don't want to un-backfill
    }
};
