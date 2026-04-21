<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usages_sous_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('sous_categorie_id')->constrained('sous_categories')->cascadeOnDelete();
            $table->string('usage', 50);
            $table->timestamps();

            $table->unique(['association_id', 'sous_categorie_id', 'usage'], 'usages_sc_unique');
            $table->index(['association_id', 'usage'], 'usages_sc_asso_usage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usages_sous_categories');
    }
};
