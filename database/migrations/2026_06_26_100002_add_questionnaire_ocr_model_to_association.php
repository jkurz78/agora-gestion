<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table): void {
            $table->string('questionnaire_ocr_model')->nullable()->after('invoice_ocr_model');
        });
    }

    public function down(): void
    {
        Schema::table('association', fn (Blueprint $t) => $t->dropColumn('questionnaire_ocr_model'));
    }
};
