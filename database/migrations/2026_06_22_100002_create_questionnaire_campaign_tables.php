<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('questionnaire_templates')->nullOnDelete();
            $table->string('titre_affiche');
            $table->text('intro')->nullable();
            $table->text('remerciement')->nullable();
            $table->string('statut')->default('brouillon'); // App\Enums\StatutCampagne
            $table->timestamp('ouverte_at')->nullable();
            $table->timestamp('cloturee_at')->nullable();
            $table->timestamps();
            $table->index(['operation_id', 'statut']);
        });

        Schema::create('questionnaire_campaign_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('aide')->nullable();
            $table->string('type');
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('obligatoire')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'ordre']);
        });

        Schema::create('questionnaire_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('participants')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();      // sha256 du token clair (D18) — lookup public
            $table->text('token_chiffre');                   // token clair chiffré (Laravel encrypted) — pour reconstruire QR/lien/relance. Une fuite DB seule (sans APP_KEY) reste inexploitable.
            $table->string('code_court', 16);                // secours back-office uniquement
            $table->string('statut')->default('non_ouvert'); // App\Enums\StatutInvitation
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index('campaign_id');
            $table->unique(['campaign_id', 'participant_id']); // une invitation par participant/campagne
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_invitations');
        Schema::dropIfExists('questionnaire_campaign_questions');
        Schema::dropIfExists('questionnaire_campaigns');
    }
};
