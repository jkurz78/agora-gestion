<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_bearer_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('questionnaire_campaigns')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamps();
        });

        // Make invitation_id nullable on questionnaire_submissions
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            // Drop existing FK before altering column
            $table->dropForeign(['invitation_id']);
            $table->foreignId('invitation_id')->nullable()->change();
            $table->foreign('invitation_id')->references('id')->on('questionnaire_invitations')->cascadeOnDelete();

            // Add bearer_token_id FK
            $table->foreignId('bearer_token_id')->nullable()->constrained('questionnaire_bearer_tokens')->nullOnDelete();
        });

        Schema::table('questionnaire_paper_scans', function (Blueprint $table): void {
            $table->foreignId('bearer_token_id')->nullable()->constrained('questionnaire_bearer_tokens')->nullOnDelete();
        });

        Schema::table('questionnaire_ocr_drafts', function (Blueprint $table): void {
            $table->foreignId('bearer_token_id')->nullable()->constrained('questionnaire_bearer_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_ocr_drafts', function (Blueprint $table): void {
            $table->dropForeign(['bearer_token_id']);
            $table->dropColumn('bearer_token_id');
        });

        Schema::table('questionnaire_paper_scans', function (Blueprint $table): void {
            $table->dropForeign(['bearer_token_id']);
            $table->dropColumn('bearer_token_id');
        });

        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->dropForeign(['bearer_token_id']);
            $table->dropColumn('bearer_token_id');

            // Restore invitation_id to non-nullable
            $table->dropForeign(['invitation_id']);
            $table->foreignId('invitation_id')->nullable(false)->change();
            $table->foreign('invitation_id')->references('id')->on('questionnaire_invitations')->cascadeOnDelete();
        });

        Schema::dropIfExists('questionnaire_bearer_tokens');
    }
};
