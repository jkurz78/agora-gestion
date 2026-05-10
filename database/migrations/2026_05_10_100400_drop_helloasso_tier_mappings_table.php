<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('helloasso_tier_mappings');
    }

    public function down(): void
    {
        Schema::create('helloasso_tier_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('helloasso_form_slug', 255);
            $table->unsignedInteger('helloasso_tier_id');
            $table->string('helloasso_tier_label', 255);
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['association_id', 'helloasso_form_slug', 'helloasso_tier_id'], 'helloasso_tier_mappings_unique_tier');
            $table->index(['target_type', 'target_id'], 'helloasso_tier_mappings_target_idx');
        });
    }
};
