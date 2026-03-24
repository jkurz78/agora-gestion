<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('dons');
        Schema::dropIfExists('cotisations');
    }

    public function down(): void
    {
        // Pas de rollback — les tables sont abandonnées définitivement.
    }
};
