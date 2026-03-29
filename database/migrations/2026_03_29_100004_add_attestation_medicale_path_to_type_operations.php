<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_operations', function (Blueprint $table): void {
            $table->string('attestation_medicale_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table): void {
            $table->dropColumn('attestation_medicale_path');
        });
    }
};
