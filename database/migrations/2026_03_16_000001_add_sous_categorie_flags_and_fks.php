<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sous_categories', function (Blueprint $table): void {
            if (! Schema::hasColumn('sous_categories', 'pour_dons')) {
                $table->boolean('pour_dons')->default(false)->after('code_cerfa');
            }
            if (! Schema::hasColumn('sous_categories', 'pour_cotisations')) {
                $table->boolean('pour_cotisations')->default(false)->after('pour_dons');
            }
        });

        Schema::table('dons', function (Blueprint $table): void {
            if (! Schema::hasColumn('dons', 'sous_categorie_id')) {
                $table->foreignId('sous_categorie_id')
                    ->nullable()
                    ->after('tiers_id')
                    ->constrained('sous_categories');
            }
        });

        Schema::table('cotisations', function (Blueprint $table): void {
            if (! Schema::hasColumn('cotisations', 'sous_categorie_id')) {
                $table->foreignId('sous_categorie_id')
                    ->nullable()
                    ->after('tiers_id')
                    ->constrained('sous_categories');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cotisations', function (Blueprint $table): void {
            $table->dropForeign(['sous_categorie_id']);
            $table->dropColumn('sous_categorie_id');
        });

        Schema::table('dons', function (Blueprint $table): void {
            $table->dropForeign(['sous_categorie_id']);
            $table->dropColumn('sous_categorie_id');
        });

        Schema::table('sous_categories', function (Blueprint $table): void {
            $table->dropColumn(['pour_dons', 'pour_cotisations']);
        });
    }
};
