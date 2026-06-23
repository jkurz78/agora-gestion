<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->foreignId('invitation_id')->constrained('questionnaire_invitations')->cascadeOnDelete();
            $table->string('statut')->default('en_cours'); // App\Enums\StatutSubmission
            $table->boolean('accepte_contact')->default(false);
            $table->string('source')->default('en_ligne'); // en_ligne | papier (lot 7)
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'statut']);
            $table->index('invitation_id');
        });

        Schema::create('questionnaire_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained('questionnaire_submissions')->cascadeOnDelete();
            $table->foreignId('campaign_question_id')->constrained('questionnaire_campaign_questions')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->integer('value_integer')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->string('value_option')->nullable();
            $table->json('value_meta')->nullable(); // fige le libellé d'option choisi
            $table->timestamps();
            $table->unique(['submission_id', 'campaign_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_answers');
        Schema::dropIfExists('questionnaire_submissions');
    }
};
