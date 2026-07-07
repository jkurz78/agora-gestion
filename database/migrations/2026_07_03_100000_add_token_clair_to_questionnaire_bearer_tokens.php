<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_bearer_tokens', function (Blueprint $table): void {
            $table->string('token_clair')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_bearer_tokens', function (Blueprint $table): void {
            $table->dropColumn('token_clair');
        });
    }
};
