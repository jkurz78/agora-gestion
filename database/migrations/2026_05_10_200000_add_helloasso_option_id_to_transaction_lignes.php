<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->unsignedInteger('helloasso_option_id')->nullable()->after('helloasso_item_id');

            // Drop l'unique simple sur helloasso_item_id (1 seule ligne par item n'est
            // plus la contrainte cible — on veut 1 ligne parent + N lignes options par item).
            $table->dropUnique(['helloasso_item_id']);

            // Unique composite (item_id, option_id) :
            // - (87070, NULL)  → ligne parent (1 seule par item, protégée côté code)
            // - (87070, 18596) → ligne option 18596 (1 seule par couple item+option)
            // MySQL traite NULL comme distinct dans les uniques → la composite
            // (X, NULL) peut apparaître plusieurs fois en DB ; la protection
            // "1 seule ligne parent par item" est donc faite côté code via lookup
            // explicite where helloasso_item_id=X AND helloasso_option_id IS NULL.
            $table->unique(['helloasso_item_id', 'helloasso_option_id'], 'tl_ha_item_option_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->dropUnique('tl_ha_item_option_unique');
            $table->dropColumn('helloasso_option_id');
            $table->unique(['helloasso_item_id'], 'transaction_lignes_helloasso_item_id_unique');
        });
    }
};
