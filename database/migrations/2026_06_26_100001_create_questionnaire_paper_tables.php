<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_paper_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->string('type'); // impression | scan
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('questionnaire_paper_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('questionnaire_campaigns')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('questionnaire_invitations')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('questionnaire_paper_batches')->nullOnDelete();
            $table->foreignId('incoming_document_id')->nullable()->constrained('incoming_documents')->nullOnDelete();
            $table->string('source'); // upload | email
            $table->string('chemin_fichier');
            $table->string('qr_statut')->default('illisible'); // detecte | illisible
            $table->string('statut')->default('en_attente');   // en_attente | rattache | traite | ignore
            $table->timestamps();
            $table->index(['campaign_id', 'statut']);
        });

        Schema::create('questionnaire_ocr_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained('questionnaire_paper_scans')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('questionnaire_invitations')->nullOnDelete();
            $table->json('payload'); // { question_id: { value, confidence } }
            $table->string('statut')->default('brouillon'); // brouillon | valide | rejete
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_ocr_drafts');
        Schema::dropIfExists('questionnaire_paper_scans');
        Schema::dropIfExists('questionnaire_paper_batches');
    }
};
