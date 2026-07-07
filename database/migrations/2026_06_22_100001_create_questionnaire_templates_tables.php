<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('titre_interne');
            $table->string('titre_affiche');
            $table->text('intro')->nullable();
            $table->text('remerciement')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->index('association_id');
        });

        Schema::create('questionnaire_template_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('questionnaire_templates')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('aide')->nullable();
            $table->string('type'); // App\Enums\TypeQuestion
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('obligatoire')->default(false);
            $table->json('config')->nullable(); // { rendu, options:[{libelle,valeur,ordre}] }
            $table->timestamps();
            $table->index(['template_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_template_questions');
        Schema::dropIfExists('questionnaire_templates');
    }
};
