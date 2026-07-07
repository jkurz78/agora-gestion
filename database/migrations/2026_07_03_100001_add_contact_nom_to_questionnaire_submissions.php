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
            $table->string('contact_nom')->nullable()->after('accepte_contact');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_submissions', function (Blueprint $table): void {
            $table->dropColumn('contact_nom');
        });
    }
};
