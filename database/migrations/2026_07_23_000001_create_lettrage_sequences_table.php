<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lettrage_sequences', function (Blueprint $table): void {
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('compte_id')->constrained('comptes')->cascadeOnDelete();
            $table->unsignedBigInteger('next_value')->default(0);

            $table->primary(['association_id', 'compte_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lettrage_sequences');
    }
};
