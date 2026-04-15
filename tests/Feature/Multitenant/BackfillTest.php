<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('all rows in tenant-scoped tables have association_id populated', function (string $table): void {
    $nullCount = DB::table($table)->whereNull('association_id')->count();
    expect($nullCount)->toBe(0);
})->with([
    'tiers', 'categories', 'sous_categories',
    'transactions', 'comptes_bancaires', 'remises_bancaires', 'rapprochements_bancaires', 'virements_internes',
    'operations', 'type_operations', 'participants', 'seances',
    'factures', 'documents_previsionnels', 'budget_lines', 'exercices', 'provisions',
    'email_templates', 'message_templates', 'campagnes_email', 'formulaire_tokens',
]);
