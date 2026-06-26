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
            $table->foreignId('remplacee_par_id')->nullable()->after('source')
                ->constrained('questionnaire_submissions')->nullOnDelete();
            $table->unsignedBigInteger('active_key')->nullable()->after('remplacee_par_id');
            $table->unique('active_key');
        });

        \DB::table('questionnaire_submissions')
            ->whereIn('statut', ['en_cours', 'soumise'])
            ->update(['active_key' => \DB::raw('invitation_id')]);
    }

    public function down(): void
    {
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->dropUnique(['active_key']);
            $table->dropConstrainedForeignId('remplacee_par_id');
            $table->dropColumn('active_key');
        });
    }
};
