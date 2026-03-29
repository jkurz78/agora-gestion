<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercices', function (Blueprint $table): void {
            $table->string('helloasso_url')->nullable()->after('cloture_par_id');
        });
    }

    public function down(): void
    {
        Schema::table('exercices', function (Blueprint $table): void {
            $table->dropColumn('helloasso_url');
        });
    }
};
