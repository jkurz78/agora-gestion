<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formules_adhesion', function (Blueprint $table): void {
            $table->date('helloasso_start_date')->nullable()->after('helloasso_tier_id');
            $table->date('helloasso_end_date')->nullable()->after('helloasso_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('formules_adhesion', function (Blueprint $table): void {
            $table->dropColumn(['helloasso_start_date', 'helloasso_end_date']);
        });
    }
};
