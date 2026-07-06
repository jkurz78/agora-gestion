<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->foreignId('paper_scan_id')->nullable()
                ->constrained('questionnaire_paper_scans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paper_scan_id');
        });
    }
};
