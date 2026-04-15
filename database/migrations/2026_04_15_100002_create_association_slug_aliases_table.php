<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association_slug_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('slug_ancien', 80)->unique();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
            $table->index('association_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association_slug_aliases');
    }
};
