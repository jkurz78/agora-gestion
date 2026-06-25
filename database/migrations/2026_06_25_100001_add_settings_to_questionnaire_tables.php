<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_templates', function (Blueprint $table): void {
            $table->boolean('anonymise')->default(true)->after('actif');
            $table->boolean('autoriser_retour')->default(true)->after('anonymise');
            $table->boolean('afficher_progression')->default(true)->after('autoriser_retour');
        });

        Schema::table('questionnaire_campaigns', function (Blueprint $table): void {
            $table->boolean('anonymise')->default(true)->after('cloturee_at');
            $table->boolean('autoriser_retour')->default(true)->after('anonymise');
            $table->boolean('afficher_progression')->default(true)->after('autoriser_retour');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_templates', function (Blueprint $table): void {
            $table->dropColumn(['anonymise', 'autoriser_retour', 'afficher_progression']);
        });

        Schema::table('questionnaire_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['anonymise', 'autoriser_retour', 'afficher_progression']);
        });
    }
};
