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
        Schema::table('helloasso_form_mappings', function (Blueprint $table): void {
            $table->boolean('ignore')->default(false)->after('operation_id');
            $table->timestamp('imported_at')->nullable()->after('ignore');
            $table->foreignId('sous_categorie_id')
                ->nullable()
                ->after('imported_at')
                ->constrained('sous_categories')
                ->nullOnDelete();
        });

        // Backfill MySQL-only (SQLite ne supporte pas UPDATE ... INNER JOIN avec alias)
        if (DB::getDriverName() === 'mysql') {
            // Backfill : pour chaque form_mapping qui a déjà des transactions liées,
            // poser imported_at = MIN(created_at des transactions HelloAsso de ce form_slug)
            DB::statement('
                UPDATE helloasso_form_mappings hfm
                SET hfm.imported_at = (
                    SELECT MIN(t.created_at)
                    FROM transactions t
                    WHERE t.helloasso_form_slug = hfm.form_slug
                )
                WHERE EXISTS (
                    SELECT 1 FROM transactions t
                    WHERE t.helloasso_form_slug = hfm.form_slug
                )
            ');

            // Backfill : pour chaque form_mapping, poser sous_categorie_id depuis le défaut global selon le type
            DB::statement("
                UPDATE helloasso_form_mappings hfm
                INNER JOIN helloasso_parametres hp ON hp.id = hfm.helloasso_parametres_id
                SET hfm.sous_categorie_id = CASE hfm.form_type
                    WHEN 'Membership' THEN hp.sous_categorie_cotisation_id
                    WHEN 'Donation' THEN hp.sous_categorie_don_id
                    WHEN 'Registration' THEN hp.sous_categorie_inscription_id
                    ELSE NULL
                END
                WHERE hfm.imported_at IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::table('helloasso_form_mappings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sous_categorie_id');
            $table->dropColumn(['ignore', 'imported_at']);
        });
    }
};
