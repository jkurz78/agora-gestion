<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_template_questions', function (Blueprint $table): void {
            $table->boolean('grouper_avec_precedente')->default(false)->after('obligatoire');
        });

        Schema::table('questionnaire_campaign_questions', function (Blueprint $table): void {
            $table->boolean('grouper_avec_precedente')->default(false)->after('obligatoire');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_template_questions', function (Blueprint $table): void {
            $table->dropColumn('grouper_avec_precedente');
        });

        Schema::table('questionnaire_campaign_questions', function (Blueprint $table): void {
            $table->dropColumn('grouper_avec_precedente');
        });
    }
};
