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
        DB::transaction(function (): void {
            // Detect orphan rows (legacy data with no association_id yet).
            // On a fresh install every table is empty → nothing to backfill,
            // and we explicitly do NOT create a placeholder association so
            // that the super-admin onboarding stays clean.
            $hasOrphans = false;
            foreach ($this->tables as $table) {
                if (DB::table($table)->whereNull('association_id')->exists()) {
                    $hasOrphans = true;
                    break;
                }
            }

            if (! $hasOrphans) {
                return;
            }

            // Legacy install: rebind orphan rows. Create a transition placeholder
            // only if no association exists yet.
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
        });
    }

    public function down(): void
    {
        // No-op: we don't want to un-backfill
    }
};
