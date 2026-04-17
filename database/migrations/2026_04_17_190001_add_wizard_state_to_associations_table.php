<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->json('wizard_state')->nullable()->after('wizard_completed_at');
            $table->unsignedTinyInteger('wizard_current_step')->default(1)->after('wizard_state');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn(['wizard_state', 'wizard_current_step']);
        });
    }
};
