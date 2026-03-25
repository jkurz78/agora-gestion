<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seance_id')->constrained('seances')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('participants')->cascadeOnDelete();
            $table->text('statut')->nullable();
            $table->text('kine')->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->unique(['seance_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presences');
    }
};
