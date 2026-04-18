<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('association_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('association_id')->nullable()->change();
            });
        }
    }
};
